<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /storefront/me/orders — the order history a token-authenticated visitor sees.
 *
 * The theme's history rendered from Liquid `customer.orders`, which only a Shopify-logged-in
 * session has: an SMS-logged-in customer saw "no orders yet" forever, whatever they bought.
 * This endpoint is the token-side source the theme now hydrates from.
 */
class MeOrdersEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shopify.storefront_token_secret' => 'test-storefront-secret']);

        Customer::query()->create([
            'email' => 'orders@example.com',
            'shopify_customer_id' => '900888',
        ]);
    }

    public function test_the_endpoint_answers_the_frozen_envelope(): void
    {
        // Shopify is not connected in tests, so the list is empty — the contract under test
        // is the route, the auth, and the envelope, not the Shopify read (proven elsewhere).
        $this->getJson('/storefront/me/orders', [
            'Authorization' => 'Bearer '.StorefrontToken::mint('900888'),
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.orders', []);
    }

    public function test_no_token_no_orders(): void
    {
        $this->getJson('/storefront/me/orders')->assertStatus(401);
    }

    public function test_a_preview_token_may_read_orders(): void
    {
        // Read-only preview means READ — support looking at what the customer sees includes
        // their order history.
        $this->getJson('/storefront/me/orders', [
            'Authorization' => 'Bearer '.StorefrontToken::mintPreview('900888'),
        ])->assertOk();
    }
}
