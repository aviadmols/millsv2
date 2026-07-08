<?php

namespace Tests\Feature;

use App\Mail\OtpMail;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.storefront_token_secret' => 'feature-test-secret']);
        Mail::fake();
    }

    public function test_request_issues_a_code_and_emails_an_existing_customer(): void
    {
        Customer::query()->create(['email' => 'anat@example.com', 'shopify_customer_id' => '999']);

        $this->postJson('/storefront/auth/otp/request', ['email' => 'anat@example.com'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseCount('otp_codes', 1);
        Mail::assertSent(OtpMail::class);
    }

    public function test_request_does_not_email_unknown_destination_but_still_ok(): void
    {
        $this->postJson('/storefront/auth/otp/request', ['email' => 'stranger@example.com'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        Mail::assertNothingSent(); // anti-enumeration: ok, but nothing delivered
    }

    public function test_verify_with_correct_code_mints_a_storefront_token(): void
    {
        $customer = Customer::query()->create(['email' => 'anat@example.com', 'shopify_customer_id' => '30801169416496']);
        OtpCode::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'email',
            'destination' => 'anat@example.com',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/storefront/auth/otp/verify', [
            'email' => 'anat@example.com',
            'code' => '123456',
        ])->assertOk()->assertJson(['ok' => true]);

        $token = $response->json('data.token');
        $this->assertSame('30801169416496', StorefrontToken::verify($token));

        // The minted token authenticates /storefront/me.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/storefront/me')
            ->assertOk()
            ->assertJson(['ok' => true, 'data' => ['customer' => ['numeric_id' => '30801169416496']]]);
    }

    public function test_verify_with_wrong_code_fails(): void
    {
        $customer = Customer::query()->create(['email' => 'anat@example.com', 'shopify_customer_id' => '1']);
        OtpCode::query()->create([
            'customer_id' => $customer->id,
            'channel' => 'email',
            'destination' => 'anat@example.com',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/storefront/auth/otp/verify', ['email' => 'anat@example.com', 'code' => '000000'])
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => 'invalid_code']);
    }

    public function test_me_requires_a_valid_token(): void
    {
        $this->getJson('/storefront/me')
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'error' => 'unauthenticated']);
    }
}
