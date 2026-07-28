<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Mail\OtpMail;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use App\Modules\MillsSubscriptions\Support\SmsTemplate;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\PhoneNumber;
use App\Support\StorefrontToken;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * OTP login for the personal area (ARCHITECTURE.md §6). Codes are stored HASHED,
 * short-lived, and rate-limited. On verify, mints the frozen v1-format storefront
 * token so every existing storefront endpoint keeps working (D8).
 */
class OtpService
{
    // === CONSTANTS ===
    public const TTL_MINUTES = 10;

    public const RATE_LIMIT = 3;          // requests per window per destination

    public const RATE_WINDOW_MINUTES = 15;

    public const MAX_VERIFY_ATTEMPTS = 5;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public function __construct(
        private readonly SmsSender $sms,
        private readonly ShopifyCustomerService $shopifyCustomers,
        private readonly LegacyCustomerImporter $importer,
    ) {}

    /**
     * Issue a code to the destination. Always returns ok=true for a valid
     * destination shape (anti-enumeration); actually sends only when a customer
     * exists on that destination.
     *
     * @return array{ok: bool, error?: string, retry_after_seconds?: int}
     */
    public function request(string $destination, string $channel = self::CHANNEL_EMAIL): array
    {
        $key = $this->canonical($destination, $channel);
        if ($key === null) {
            return ['ok' => false, 'error' => 'invalid_destination'];
        }

        $recent = OtpCode::query()
            ->where('destination', $key)
            ->where('created_at', '>=', now()->subMinutes(self::RATE_WINDOW_MINUTES))
            ->count();
        if ($recent >= self::RATE_LIMIT) {
            return ['ok' => false, 'error' => 'rate_limited', 'retry_after_seconds' => self::RATE_WINDOW_MINUTES * 60];
        }

        $customer = $this->findCustomer($destination, $channel);

        /*
         * A code also goes to someone we have never imported.
         *
         * The legacy (iCount) population lives only in Shopify — their subscription is JSON on
         * the Shopify customer note and there is no row here at all. Requiring a local customer
         * meant precisely the people who need to move onto the new system were the ones who
         * could not log in to be told so. If Shopify knows this phone, that is a real customer.
         */
        $deliver = $customer !== null
            || ($channel === self::CHANNEL_SMS && $this->shopifyKnowsPhone($destination));

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::query()->create([
            'customer_id' => $customer?->id,
            'channel' => $channel,
            'destination' => $key,          // canonical, so verify() finds it back
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        // Only actually deliver when the destination maps to a real customer.
        if ($deliver) {
            if ($channel === self::CHANNEL_SMS) {
                // Through SmsTemplate so the admin's edited wording is what actually goes out.
                $this->sms->send(
                    PhoneNumber::local($destination) ?? $destination,
                    SmsTemplate::render('otp.sms.body', ['code' => $code]),
                );
            } else {
                Mail::to($key)->send(new OtpMail($code, self::TTL_MINUTES));
            }
        }

        return ['ok' => true];
    }

    /**
     * Verify a code and, on success, mint a storefront token for the customer.
     *
     * @return array{ok: bool, error?: string, token?: string, customer?: array<string,mixed>}
     */
    public function verify(
        string $destination,
        string $code,
        string $channel = self::CHANNEL_EMAIL,
        ?int $chosenCustomerId = null,
    ): array {
        $key = $this->canonical($destination, $channel);
        if ($key === null) {
            return ['ok' => false, 'error' => 'invalid_destination'];
        }

        $otp = OtpCode::query()
            ->where('destination', $key)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($otp === null) {
            return ['ok' => false, 'error' => 'invalid_or_expired'];
        }

        $otp->increment('attempts');
        if ($otp->attempts > self::MAX_VERIFY_ATTEMPTS) {
            return ['ok' => false, 'error' => 'too_many_attempts'];
        }

        if (! Hash::check($code, $otp->code_hash)) {
            return ['ok' => false, 'error' => 'invalid_code'];
        }

        // NOT consumed yet. One phone can hold several accounts, and the customer has to pick
        // one before a token exists — burning the code on the first call would strand them
        // on the chooser with nothing to submit.
        $accounts = $this->accountsFor($destination, $channel);

        if ($accounts->isEmpty()) {
            return ['ok' => false, 'error' => 'no_customer'];
        }

        if ($chosenCustomerId !== null) {
            $customer = $accounts->firstWhere('id', $chosenCustomerId);

            // The choice is constrained to the accounts this VERIFIED phone actually owns.
            // Trusting the submitted id would let anyone with any code open any account.
            if ($customer === null) {
                return ['ok' => false, 'error' => 'account_not_available'];
            }

            return $this->issue($otp, $customer);
        }

        if ($accounts->count() > 1) {
            return [
                'ok' => true,
                'needs_account_choice' => true,
                'accounts' => $accounts->map(fn (Customer $c) => [
                    'id' => $c->id,
                    'name' => $c->fullName(),
                    'email' => $c->email,
                ])->values()->all(),
            ];
        }

        return $this->issue($otp, $accounts->first());
    }

    /**
     * Every account this phone can open — ours and Shopify's.
     *
     * A legacy (iCount) customer exists only in Shopify, with their subscription as JSON on
     * the customer note, so logging in has to be able to pull them across. It happens HERE,
     * after the code has been checked: importing on request() would let anyone create rows in
     * our database by typing phone numbers.
     *
     * @return Collection<int, Customer>
     */
    private function accountsFor(string $destination, string $channel): Collection
    {
        $local = $this->findCustomers($destination, $channel);

        if ($channel !== self::CHANNEL_SMS) {
            return $local;
        }

        $known = $local->pluck('shopify_customer_id')->filter()->map(fn ($id) => (string) $id)->all();

        foreach ($this->shopifyMatches($destination) as $remote) {
            $shopifyId = (string) ($remote['id'] ?? '');

            if ($shopifyId === '' || in_array($shopifyId, $known, true)) {
                continue;
            }

            // Brings the customer, and the subscription hiding in their note, into the DB —
            // behind the card-update wall, so nothing can be charged until they enter a card.
            $result = $this->importer->import($shopifyId, null, Timeline::ACTOR_CUSTOMER);

            if ($result['customer_id'] !== null) {
                $imported = Customer::query()->find($result['customer_id']);

                if ($imported !== null) {
                    $local->push($imported);
                    $known[] = $shopifyId;
                }
            }
        }

        // A token's subject IS the Shopify id, so an account without one cannot be opened.
        return $local->filter(fn (Customer $c) => filled($c->shopify_customer_id))->values();
    }

    /**
     * @return array{ok: bool, token?: string, customer?: array<string,mixed>}
     */
    private function issue(OtpCode $otp, Customer $customer): array
    {
        $otp->forceFill(['consumed_at' => now(), 'customer_id' => $customer->id])->save();

        return [
            'ok' => true,
            'token' => StorefrontToken::mint((string) $customer->shopify_customer_id),
            'customer' => [
                'id' => $customer->id,
                'shopify_customer_id' => $customer->shopify_customer_id,
                'email' => $customer->email,
                'name' => $customer->fullName(),
            ],
        ];
    }

    /** Does Shopify hold this phone? Cheap enough to ask before spending an SMS. */
    private function shopifyKnowsPhone(string $phone): bool
    {
        return $this->shopifyMatches($phone) !== [];
    }

    /** @return list<array<string, mixed>> */
    private function shopifyMatches(string $phone): array
    {
        try {
            return $this->shopifyCustomers->searchByPhone($phone);
        } catch (Throwable $e) {
            // Shopify being down must not take the login with it: a customer we already hold
            // still gets in, and the code still sends.
            SystemLog::warning('otp', 'could not search Shopify for this phone', [
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * SMS logins are matched on the NORMALISED phone key, never the raw string —
     * `050-123-4567`, `0501234567` and `+972501234567` are the same customer, and an
     * exact-string match would find none of them.
     */
    private function findCustomer(string $destination, string $channel): ?Customer
    {
        return $this->findCustomers($destination, $channel)->first();
    }

    /**
     * ALL the local accounts on this destination.
     *
     * One phone number legitimately holds more than one account — a household with two email
     * addresses, or a customer who checked out twice. findByPhone() returns only the first,
     * which silently picked one for them; the customer chooses instead.
     *
     * @return Collection<int, Customer>
     */
    private function findCustomers(string $destination, string $channel): Collection
    {
        if ($channel === self::CHANNEL_SMS) {
            $key = PhoneNumber::normalise($destination);

            return $key === null
                ? collect()
                : Customer::query()->where('phone_normalized', $key)->orderBy('id')->get();
        }

        return Customer::query()->where('email', trim(strtolower($destination)))->orderBy('id')->get();
    }

    /**
     * The single spelling of a destination that everything keys on — the OTP row, the
     * rate limit, and the verify lookup. Without it, requesting a code with one
     * spelling and entering it with another would never match.
     */
    private function canonical(string $destination, string $channel): ?string
    {
        if ($channel === self::CHANNEL_SMS) {
            return PhoneNumber::normalise($destination);
        }

        $email = trim(strtolower($destination));

        return $email === '' ? null : $email;
    }
}
