<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use App\Modules\MillsSubscriptions\Services\OtpService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Everyone with a phone number gets in — including the customers who only exist in Shopify.
 *
 * The legacy (iCount) population has no row here at all: their subscription is JSON on the
 * Shopify customer note, and v2's one-time import deliberately skipped them. Requiring a local
 * customer meant precisely the people who need to move onto the new system were the ones who
 * could not log in to be told so.
 *
 * Logging in now pulls them across — behind the card-update wall, so nothing can be charged
 * until they enter a card, and entering one is what makes them a PayMe customer.
 */
class LegacyLoginTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '0524778992';

    private const NOTE = '{"discount":0.9,"interval":1,"status":"account-active","dogs":[{"status":"active","quizData":{"allergy":[],"age":8,"weight":3,"activity":0,"body":1},"name":"כלב 1","sex":0,"avatar":1,"caloriesPerDay":191,"variants":[{"id":39357390782621,"grams":1530,"price":171}]}],"nextDelivery":"2026-06-18"}';

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Without a secret StorefrontToken::verify() returns null for everything, so the
        // token assertions below would pass on a broken implementation too.
        config(['shopify.storefront_token_secret' => 'test-storefront-secret']);

        $spy = new class($this->sent) implements SmsSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $message): bool
            {
                $this->sent[] = $message;

                return true;
            }
        };
        $this->app->instance(SmsSender::class, $spy);
    }

    /**
     * Shopify holding the given customers.
     *
     * Only the HTTP-facing search() is faked, so the real searchByPhone() — including the
     * check that confirms the number actually belongs to the account it returns — is the code
     * under test. Faking searchByPhone() itself would skip the very logic that was broken.
     */
    private function fakeShopify(array $customers): void
    {
        $this->app->instance(ShopifyCustomerService::class, new class($customers) extends ShopifyCustomerService
        {
            public function __construct(private array $customers) {}

            public function search(string $term, int $limit = 20): array
            {
                return $this->customers;
            }

            public function find(string $idOrGid): array
            {
                foreach ($this->customers as $c) {
                    if ((string) $c['id'] === (string) $idOrGid) {
                        return $c;
                    }
                }

                return [];
            }
        });
    }

    /** @return array<string, mixed> */
    private function shopifyCustomer(string $id, string $email, string $note = ''): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'phone' => '+972'.substr(self::PHONE, 1),
            'first_name' => 'Anat',
            'last_name' => 'Levi',
            'note' => $note,
            'default_address' => ['address1' => 'Herzl 1', 'city' => 'Tel Aviv'],
        ];
    }

    /**
     * The code the customer actually received.
     *
     * Read out of the SMS the spy captured, not recovered from the stored hash — the hash is
     * bcrypt and cannot be read back. This also proves the code that reaches the phone is the
     * one the server will accept.
     */
    private function sentCode(): string
    {
        $this->assertNotEmpty($this->sent, 'no SMS was sent, so there is no code to read');

        preg_match('/(\d{6})/', end($this->sent), $m);

        $this->assertNotEmpty($m, 'the SMS carried no 6-digit code');

        return $m[1];
    }

    public function test_a_customer_who_exists_only_in_shopify_receives_a_code(): void
    {
        $this->fakeShopify([$this->shopifyCustomer('900111', 'legacy@example.com', self::NOTE)]);

        $this->assertSame(0, Customer::query()->count());

        $result = app(OtpService::class)->request(self::PHONE, OtpService::CHANNEL_SMS);

        $this->assertTrue($result['ok']);
        // The whole point: no local row, and a code still goes out.
        $this->assertCount(1, $this->sent);
    }

    public function test_logging_in_imports_the_subscription_from_the_shopify_note(): void
    {
        $this->fakeShopify([$this->shopifyCustomer('900111', 'legacy@example.com', self::NOTE)]);

        $otp = app(OtpService::class);
        $otp->request(self::PHONE, OtpService::CHANNEL_SMS);
        $result = $otp->verify(self::PHONE, $this->sentCode(), OtpService::CHANNEL_SMS);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['token']);

        $customer = Customer::query()->where('shopify_customer_id', '900111')->firstOrFail();
        $subscription = Subscription::query()->where('customer_id', $customer->id)->firstOrFail();

        // Their subscription came across from the note…
        $this->assertSame(1, $customer->dogs()->count());
        // …behind the card-update wall, so billing cannot touch it yet.
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->payment_state);

        // And the token actually opens the personal area.
        $this->assertSame('900111', StorefrontToken::verify($result['token']));
    }

    public function test_updating_the_card_turns_them_into_a_payme_customer(): void
    {
        $this->fakeShopify([$this->shopifyCustomer('900111', 'legacy@example.com', self::NOTE)]);

        $otp = app(OtpService::class);
        $otp->request(self::PHONE, OtpService::CHANNEL_SMS);
        $otp->verify(self::PHONE, $this->sentCode(), OtpService::CHANNEL_SMS);

        $customer = Customer::query()->where('shopify_customer_id', '900111')->firstOrFail();
        $subscription = Subscription::query()->where('customer_id', $customer->id)->firstOrFail();

        app(CardUpdateService::class)->storeBuyerKey($customer, 'bk_live', '**** 4242');
        $lifted = app(CardUpdateService::class)->liftCardUpdateWall($customer);

        // The migration completes itself: the wall lifts and they are billable.
        $this->assertSame(1, $lifted);
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
    }

    public function test_a_customer_with_no_subscription_can_still_log_in(): void
    {
        // "Everyone can log in" includes a Shopify customer with an empty note — they simply
        // have nothing to show yet, which is not a reason to lock them out.
        $this->fakeShopify([$this->shopifyCustomer('900222', 'plain@example.com', '')]);

        $otp = app(OtpService::class);
        $otp->request(self::PHONE, OtpService::CHANNEL_SMS);
        $result = $otp->verify(self::PHONE, $this->sentCode(), OtpService::CHANNEL_SMS);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['token']);
        $this->assertSame(0, Subscription::query()->count());
    }

    // --- one phone, several accounts -----------------------------------------

    public function test_several_accounts_on_one_phone_are_offered_as_a_choice(): void
    {
        $this->fakeShopify([
            $this->shopifyCustomer('900111', 'first@example.com', self::NOTE),
            $this->shopifyCustomer('900222', 'second@example.com', ''),
        ]);

        $otp = app(OtpService::class);
        $otp->request(self::PHONE, OtpService::CHANNEL_SMS);
        $result = $otp->verify(self::PHONE, $this->sentCode(), OtpService::CHANNEL_SMS);

        // No token yet: picking one FOR them would silently open somebody else's subscription.
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['needs_account_choice']);
        $this->assertCount(2, $result['accounts']);
        $this->assertArrayNotHasKey('token', $result);
    }

    public function test_choosing_an_account_issues_the_token_for_that_account(): void
    {
        $this->fakeShopify([
            $this->shopifyCustomer('900111', 'first@example.com', self::NOTE),
            $this->shopifyCustomer('900222', 'second@example.com', ''),
        ]);

        $otp = app(OtpService::class);
        $otp->request(self::PHONE, OtpService::CHANNEL_SMS);
        $code = $this->sentCode();

        $listed = $otp->verify(self::PHONE, $code, OtpService::CHANNEL_SMS);
        $chosen = collect($listed['accounts'])->firstWhere('email', 'second@example.com');

        // The SAME code works for the second call — it is only consumed once a token exists.
        $result = $otp->verify(self::PHONE, $code, OtpService::CHANNEL_SMS, (int) $chosen['id']);

        $this->assertTrue($result['ok']);
        $this->assertSame('900222', StorefrontToken::verify($result['token']));
    }

    public function test_you_cannot_open_an_account_that_is_not_yours(): void
    {
        $stranger = Customer::query()->create([
            'email' => 'stranger@example.com',
            'shopify_customer_id' => '777777',
            'phone' => '0509999999',
        ]);

        $this->fakeShopify([$this->shopifyCustomer('900111', 'mine@example.com', self::NOTE)]);

        $otp = app(OtpService::class);
        $otp->request(self::PHONE, OtpService::CHANNEL_SMS);

        // A valid code for MY phone must not open an account belonging to a different phone.
        $result = $otp->verify(self::PHONE, $this->sentCode(), OtpService::CHANNEL_SMS, $stranger->id);

        $this->assertFalse($result['ok']);
        $this->assertSame('account_not_available', $result['error']);
    }

    public function test_a_customer_whose_number_is_only_on_their_address_is_found(): void
    {
        /*
         * THE REAL CASE. Plenty of Shopify customers have an empty `customer.phone` and their
         * number only on the default address — that is what checkout collects. Confirming the
         * match against the customer field alone threw exactly those people away: Shopify found
         * them, and we dropped them on the floor with "no customer with that number".
         */
        $onAddressOnly = [
            'id' => '900333',
            'email' => 'address@example.com',
            'phone' => null,                       // empty on the customer record
            'first_name' => 'Yehiel',
            'last_name' => 'Ohana',
            'note' => '',
            'default_address' => ['phone' => self::PHONE, 'address1' => 'Herzl 1'],
        ];

        $this->fakeShopify([$onAddressOnly]);

        $otp = app(OtpService::class);
        $otp->request(self::PHONE, OtpService::CHANNEL_SMS);

        $this->assertCount(1, $this->sent, 'a code must go to a customer whose number is on their address');

        $result = $otp->verify(self::PHONE, $this->sentCode(), OtpService::CHANNEL_SMS);

        $this->assertTrue($result['ok']);
        $this->assertSame('900333', StorefrontToken::verify($result['token']));
    }

    public function test_a_phone_nobody_holds_gets_no_code_and_no_account(): void
    {
        $this->fakeShopify([]);

        $otp = app(OtpService::class);
        $result = $otp->request('0500000001', OtpService::CHANNEL_SMS);

        // Still ok:true — the form must not reveal which numbers hold a subscription — but
        // no SMS is spent, and no account is invented.
        $this->assertTrue($result['ok']);
        $this->assertCount(0, $this->sent);

        // No SMS went out, so there is no code to read — pin a known one to reach the branch
        // under test, which is what verify() does when the code is RIGHT and nobody owns
        // the number.
        OtpCode::query()->latest('id')->firstOrFail()->forceFill(['code_hash' => Hash::make('123456')])->save();

        $verify = $otp->verify('0500000001', '123456', OtpService::CHANNEL_SMS);
        $this->assertFalse($verify['ok']);
        $this->assertSame('no_customer', $verify['error']);
    }

    public function test_an_existing_payme_customer_is_not_re_imported(): void
    {
        $customer = Customer::query()->create([
            'email' => 'payme@example.com',
            'shopify_customer_id' => '900111',
            'phone' => self::PHONE,
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $this->fakeShopify([$this->shopifyCustomer('900111', 'payme@example.com', self::NOTE)]);

        $otp = app(OtpService::class);
        $otp->request(self::PHONE, OtpService::CHANNEL_SMS);
        $result = $otp->verify(self::PHONE, $this->sentCode(), OtpService::CHANNEL_SMS);

        $this->assertTrue($result['ok']);
        // Re-importing would have created a SECOND subscription and doubled their billing.
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
    }
}
