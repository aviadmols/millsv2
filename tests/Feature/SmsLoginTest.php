<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OtpCode;
use App\Modules\MillsSubscriptions\Services\Sms\SmsSender;
use App\Support\PhoneNumber;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SMS login for the personal area: the customer types their phone number, we text
 * them a code, and verifying it hands back the frozen storefront token the theme's
 * account area already uses.
 *
 * The thing this pins down is that a phone number is not a string. Customers type
 * `050-123-4567`, Shopify stores `+972501234567`, and an import may have left
 * `0501234567`. All three are one person, and the login has to find them.
 */
class SmsLoginTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{phone: string, message: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['shopify.storefront_token_secret' => 'test-storefront-secret']);

        // Capture SMS instead of calling 019.
        $this->app->bind(SmsSender::class, fn () => new class($this->sent) implements SmsSender
        {
            public function __construct(private array &$sent) {}

            public function send(string $phone, string $message): bool
            {
                $this->sent[] = ['phone' => $phone, 'message' => $message];

                return true;
            }
        });
    }

    private function customer(string $storedPhone): Customer
    {
        return Customer::query()->create([
            'shopify_customer_id' => '880099',
            'email' => 'sms@example.com',
            'first_name' => 'Dana',
            'phone' => $storedPhone,
        ]);
    }

    private function codeFor(string $destination): string
    {
        // Re-derive the code by brute force is silly; instead assert on what we sent.
        $sms = end($this->sent);
        preg_match('/(\d{6})/', $sms['message'], $m);

        return $m[1];
    }

    // --- the number itself ---------------------------------------------------

    public function test_every_spelling_of_a_number_collapses_to_the_same_key(): void
    {
        $key = '501234567';

        foreach (['050-123-4567', '0501234567', '+972501234567', '972-50-123-4567', '(050) 123 4567'] as $spelling) {
            $this->assertSame($key, PhoneNumber::normalise($spelling), $spelling);
        }

        $this->assertNull(PhoneNumber::normalise('123'));
        $this->assertNull(PhoneNumber::normalise(null));
    }

    public function test_a_customer_is_found_however_the_number_is_typed(): void
    {
        $this->customer('+972501234567');

        foreach (['050-123-4567', '0501234567', '+972501234567'] as $typed) {
            $this->assertNotNull(Customer::findByPhone($typed), "should find the customer from {$typed}");
        }
    }

    // --- the flow ------------------------------------------------------------

    public function test_requesting_a_code_texts_the_customer(): void
    {
        $this->customer('+972501234567');

        $this->postJson('/storefront/auth/otp/request', [
            'phone' => '050-123-4567',
            'channel' => 'sms',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertCount(1, $this->sent);
        $this->assertSame('0501234567', $this->sent[0]['phone'], 'dialled in the local form');
        $this->assertMatchesRegularExpression('/\d{6}/', $this->sent[0]['message'], 'the code is in the text');
    }

    public function test_verifying_the_code_returns_a_working_storefront_token(): void
    {
        $customer = $this->customer('0501234567');

        // Ask with one spelling…
        $this->postJson('/storefront/auth/otp/request', [
            'phone' => '+972501234567',
            'channel' => 'sms',
        ])->assertOk();

        $code = $this->codeFor('0501234567');

        // …and verify with another. It must still match.
        $response = $this->postJson('/storefront/auth/otp/verify', [
            'phone' => '050-123-4567',
            'channel' => 'sms',
            'code' => $code,
        ])->assertOk()->assertJsonPath('ok', true);

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        // The token is the frozen format, and it opens the personal area.
        $this->assertSame('880099', StorefrontToken::verify($token));

        $this->getJson('/storefront/me', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.customer.numeric_id', '880099');
    }

    public function test_a_wrong_code_does_not_log_you_in(): void
    {
        $this->customer('0501234567');

        $this->postJson('/storefront/auth/otp/request', ['phone' => '0501234567', 'channel' => 'sms'])->assertOk();

        $this->postJson('/storefront/auth/otp/verify', [
            'phone' => '0501234567',
            'channel' => 'sms',
            'code' => '000000',
        ])->assertStatus(401)->assertJsonPath('ok', false);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $this->customer('0501234567');

        $this->postJson('/storefront/auth/otp/request', ['phone' => '0501234567', 'channel' => 'sms'])->assertOk();
        $code = $this->codeFor('0501234567');

        $this->postJson('/storefront/auth/otp/verify', ['phone' => '0501234567', 'channel' => 'sms', 'code' => $code])
            ->assertOk();

        $this->postJson('/storefront/auth/otp/verify', ['phone' => '0501234567', 'channel' => 'sms', 'code' => $code])
            ->assertStatus(401);
    }

    public function test_an_unknown_number_is_never_texted_but_the_answer_looks_the_same(): void
    {
        // Anti-enumeration: the response must not reveal whether the number is a customer.
        $this->postJson('/storefront/auth/otp/request', ['phone' => '0509999999', 'channel' => 'sms'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertCount(0, $this->sent, 'no SMS to a number we do not know');
        $this->assertSame(1, OtpCode::query()->count(), 'a code row is still written, so timing does not leak either');
    }

    public function test_the_stored_code_is_hashed_not_plain(): void
    {
        $this->customer('0501234567');

        $this->postJson('/storefront/auth/otp/request', ['phone' => '0501234567', 'channel' => 'sms'])->assertOk();
        $code = $this->codeFor('0501234567');

        $this->assertNotSame($code, OtpCode::query()->firstOrFail()->code_hash);
    }
}
