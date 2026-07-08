<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Mail\OtpMail;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use App\Support\StorefrontToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

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

    public function __construct(private readonly SmsSender $sms) {}

    /**
     * Issue a code to the destination. Always returns ok=true for a valid
     * destination shape (anti-enumeration); actually sends only when a customer
     * exists on that destination.
     *
     * @return array{ok: bool, error?: string, retry_after_seconds?: int}
     */
    public function request(string $destination, string $channel = self::CHANNEL_EMAIL): array
    {
        $destination = trim($destination);
        if ($destination === '') {
            return ['ok' => false, 'error' => 'invalid_destination'];
        }

        $recent = OtpCode::query()
            ->where('destination', $destination)
            ->where('created_at', '>=', now()->subMinutes(self::RATE_WINDOW_MINUTES))
            ->count();
        if ($recent >= self::RATE_LIMIT) {
            return ['ok' => false, 'error' => 'rate_limited', 'retry_after_seconds' => self::RATE_WINDOW_MINUTES * 60];
        }

        $customer = $this->findCustomer($destination, $channel);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        OtpCode::query()->create([
            'customer_id' => $customer?->id,
            'channel' => $channel,
            'destination' => $destination,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        // Only actually deliver when the destination maps to a real customer.
        if ($customer !== null) {
            if ($channel === self::CHANNEL_SMS) {
                $this->sms->send($destination, __('otp.sms.body', ['code' => $code]));
            } else {
                Mail::to($destination)->send(new OtpMail($code, self::TTL_MINUTES));
            }
        }

        return ['ok' => true];
    }

    /**
     * Verify a code and, on success, mint a storefront token for the customer.
     *
     * @return array{ok: bool, error?: string, token?: string, customer?: array<string,mixed>}
     */
    public function verify(string $destination, string $code, string $channel = self::CHANNEL_EMAIL): array
    {
        $destination = trim($destination);

        $otp = OtpCode::query()
            ->where('destination', $destination)
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

        $otp->forceFill(['consumed_at' => now()])->save();

        $customer = $this->findCustomer($destination, $channel);
        if ($customer === null || $customer->shopify_customer_id === null) {
            // No linked customer (or not yet linked to Shopify) — can't mint a
            // frozen-format token whose subject is the Shopify id.
            return ['ok' => false, 'error' => 'no_customer'];
        }

        $token = StorefrontToken::mint((string) $customer->shopify_customer_id);

        return [
            'ok' => true,
            'token' => $token,
            'customer' => [
                'id' => $customer->id,
                'shopify_customer_id' => $customer->shopify_customer_id,
                'email' => $customer->email,
                'name' => $customer->fullName(),
            ],
        ];
    }

    private function findCustomer(string $destination, string $channel): ?Customer
    {
        $column = $channel === self::CHANNEL_SMS ? 'phone' : 'email';

        return Customer::query()->where($column, $destination)->first();
    }
}
