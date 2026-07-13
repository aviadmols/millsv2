<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin has to run inside Shopify's iframe, not in a tab of its own.
 *
 * Two things must hold: Shopify is allowed to frame us, and the embed context
 * survives Filament's redirects — App Bridge needs `host`, and a redirect that
 * drops it kicks the app out of the Shopify admin.
 */
class ShopifyEmbeddedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.shop_domain' => 'millsforpets.myshopify.com']);
    }

    public function test_shopify_is_allowed_to_frame_the_admin(): void
    {
        $response = $this->get('/admin/login');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('https://admin.shopify.com', $csp);
        $this->assertStringContainsString('https://millsforpets.myshopify.com', $csp);

        // X-Frame-Options would veto the iframe regardless of the CSP.
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    public function test_a_redirect_keeps_the_embed_context(): void
    {
        // Unauthenticated /admin redirects to the login page. Shopify's host must
        // survive that hop, or App Bridge cannot initialise on the page we land on.
        $response = $this->get('/admin?host=YWRtaW4uc2hvcGlmeS5jb20&shop=millsforpets.myshopify.com&embedded=1');

        $response->assertRedirect();

        $target = $response->headers->get('Location');
        $this->assertStringContainsString('host=YWRtaW4uc2hvcGlmeS5jb20', $target);
        $this->assertStringContainsString('shop=millsforpets.myshopify.com', $target);
        $this->assertStringContainsString('embedded=1', $target);
    }

    public function test_a_redirect_without_an_embed_context_is_left_alone(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect();
        $this->assertStringNotContainsString('host=', (string) $response->headers->get('Location'));
    }

    public function test_app_bridge_is_injected_when_embedding_is_enabled(): void
    {
        config(['shopify.embedded' => true, 'shopify.api_key' => 'test-api-key-123']);

        $this->get('/admin/login')
            ->assertSuccessful()
            ->assertSee('shopify-api-key', escape: false)
            ->assertSee('app-bridge.js', escape: false);
    }

    public function test_app_bridge_is_not_injected_when_embedding_is_off(): void
    {
        config(['shopify.embedded' => false]);

        $this->get('/admin/login')
            ->assertSuccessful()
            ->assertDontSee('app-bridge.js', escape: false);
    }

    public function test_the_authenticated_panel_still_renders_inside_the_frame(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->get('/admin?host=YWRtaW4&shop=millsforpets.myshopify.com&embedded=1');

        $response->assertSuccessful();
        $this->assertStringContainsString('frame-ancestors', (string) $response->headers->get('Content-Security-Policy'));
    }
}
