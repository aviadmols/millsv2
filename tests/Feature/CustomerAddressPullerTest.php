<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ShopifyConnection;
use App\Modules\MillsSubscriptions\Services\Shopify\CustomerAddressPuller;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A customer created from a checkout had no address: an empty box in the personal area and
 * orders shipped with no address (63 customers, 24 orders in 30 days — 2026-10-18).
 */
class CustomerAddressPullerTest extends TestCase
{
    use RefreshDatabase;

    private const SHOPIFY_ADDRESS = [
        'first_name' => 'אמיר',
        'last_name' => 'ב',
        'address1' => 'גליקסון 9',
        'address2' => 'דירה 11',
        'city' => 'תל אביב',
        'country' => 'Israel',
        'zip' => '6314109',
        'phone' => '0524484477',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['shopify.storefront_token_secret' => 'test-storefront-secret']);

        ShopifyConnection::query()->create([
            'shop_domain' => 'millsforpets.myshopify.com',
            'access_token' => 'shpat_test',
            'installed_at' => now(),
        ]);
    }

    private function customer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'email' => 'a@example.com',
            'shopify_customer_id' => '7001',
            'first_name' => 'אמיר',
        ], $overrides));
    }

    public function test_the_checkout_address_is_stored_on_a_customer_who_has_none(): void
    {
        $customer = $this->customer();

        $this->assertTrue(app(CustomerAddressPuller::class)->fromOrder($customer, ['shipping_address' => self::SHOPIFY_ADDRESS]));

        $customer->refresh();
        $this->assertSame('גליקסון 9', $customer->address1);
        $this->assertSame('תל אביב', $customer->city);
        $this->assertSame('0524484477', $customer->phone);
        $this->assertSame('אמיר', $customer->first_name); // never overwritten
    }

    public function test_an_address_the_customer_already_has_is_never_overwritten(): void
    {
        Http::fake();
        $customer = $this->customer(['address1' => 'הרצל 1', 'city' => 'חיפה']);

        $this->assertFalse(app(CustomerAddressPuller::class)->fromOrder($customer, ['shipping_address' => self::SHOPIFY_ADDRESS]));
        $this->assertFalse(app(CustomerAddressPuller::class)->fromShopify($customer));

        $this->assertSame('הרצל 1', $customer->fresh()->address1);
        Http::assertNothingSent();
    }

    public function test_the_personal_area_fills_a_missing_address_from_shopify_and_shows_the_account_details(): void
    {
        Http::fake(['*/customers/7001.json*' => Http::response(['customer' => ['default_address' => self::SHOPIFY_ADDRESS]])]);
        $this->customer(['phone' => '+972533018048']);

        $response = $this->getJson('/storefront/me', ['Authorization' => 'Bearer '.StorefrontToken::mint('7001')]);

        $response->assertOk()
            ->assertJsonPath('data.customer.email', 'a@example.com')
            ->assertJsonPath('data.customer.phone', '+972533018048')
            ->assertJsonPath('data.customer.addresses.0.address1', 'גליקסון 9')
            ->assertJsonPath('data.customer.addresses.0.city', 'תל אביב');
    }

    public function test_a_shopify_failure_leaves_the_customer_as_they_were(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);
        $customer = $this->customer();

        $this->assertFalse(app(CustomerAddressPuller::class)->fromShopify($customer));
        $this->assertNull($customer->fresh()->address1);
    }
}
