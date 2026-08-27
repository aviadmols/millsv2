<?php

namespace Tests\Feature;

use App\Models\ShopifyConnection;
use App\Modules\MillsSubscriptions\Services\Shopify\PaymentCustomizationInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Switching the checkout function on.
 *
 * Deploying a Shopify Function only makes it available — it runs for nobody until a
 * payment customization points at it, and Shopify's admin gives no way to create one
 * (27 Aug: deployed, and the Payment customizations screen sat empty). So the app has
 * to do it, which means getting the two failure modes right: never stacking duplicate
 * customizations, and never claiming success when Shopify refused.
 */
class PaymentCustomizationInstallerTest extends TestCase
{
    use RefreshDatabase;

    private const FUNCTION_ID = '01234567-89ab-cdef-0123-456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        ShopifyConnection::query()->create([
            'shop_domain' => 'millsforpets.myshopify.com',
            'access_token' => 'shpat_test',
            'installed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $existing */
    private function fakeShopify(array $existing = [], ?array $mutation = null): void
    {
        Http::fake([
            '*/graphql.json' => Http::sequence()
                ->push(['data' => ['shopifyFunctions' => ['nodes' => [
                    ['id' => 'other-function', 'title' => 'Something else', 'apiType' => 'discount'],
                    ['id' => self::FUNCTION_ID, 'title' => 'hide-paypal-on-subscriptions', 'apiType' => 'payment_customization'],
                ]]]])
                ->push(['data' => ['paymentCustomizations' => ['nodes' => $existing]]])
                ->push($mutation ?? ['data' => ['paymentCustomizationCreate' => [
                    'paymentCustomization' => ['id' => 'gid://shopify/PaymentCustomization/1'],
                    'userErrors' => [],
                ]]]),
        ]);
    }

    public function test_it_creates_and_enables_the_customization(): void
    {
        $this->fakeShopify();

        $result = app(PaymentCustomizationInstaller::class)->activate();

        $this->assertSame('activated', $result['status']);

        // Enabled on creation: a customization that exists but is off hides nothing,
        // and nobody would know the difference from the admin screen.
        Http::assertSent(function ($request) {
            return ! str_contains((string) ($request['query'] ?? ''), 'paymentCustomizationCreate')
                || (($request['variables']['input']['enabled'] ?? null) === true
                    && ($request['variables']['input']['functionId'] ?? null) === self::FUNCTION_ID);
        });
    }

    public function test_running_it_twice_does_not_stack_a_second_customization(): void
    {
        // Already there and on: two customizations hiding the same method is a state
        // nobody can reason about afterwards.
        $this->fakeShopify([[
            'id' => 'gid://shopify/PaymentCustomization/1',
            'title' => 'Hide PayPal on subscriptions',
            'enabled' => true,
            'shopifyFunction' => ['id' => self::FUNCTION_ID],
        ]]);

        $result = app(PaymentCustomizationInstaller::class)->activate();

        $this->assertSame('already_active', $result['status']);
    }

    public function test_a_customization_someone_switched_off_is_switched_back_on(): void
    {
        $this->fakeShopify([[
            'id' => 'gid://shopify/PaymentCustomization/1',
            'title' => 'Hide PayPal on subscriptions',
            'enabled' => false,
            'shopifyFunction' => ['id' => self::FUNCTION_ID],
        ]], ['data' => ['paymentCustomizationUpdate' => [
            'paymentCustomization' => ['id' => 'gid://shopify/PaymentCustomization/1'],
            'userErrors' => [],
        ]]]);

        $this->assertSame('reactivated', app(PaymentCustomizationInstaller::class)->activate()['status']);
    }

    public function test_a_function_the_store_cannot_see_is_named_as_such(): void
    {
        // The everyday cause: `shopify app deploy` was never run, or the version was
        // not released. "function_not_found" is what the settings screen translates.
        Http::fake([
            '*/graphql.json' => Http::response(['data' => ['shopifyFunctions' => ['nodes' => []]]]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('function_not_found');

        app(PaymentCustomizationInstaller::class)->activate();
    }

    public function test_shopify_refusing_is_reported_not_swallowed(): void
    {
        // The refusal that will actually happen: the scope was added to the manifest
        // but the store still holds a token granted before it.
        $this->fakeShopify([], ['data' => ['paymentCustomizationCreate' => [
            'paymentCustomization' => null,
            'userErrors' => [['field' => null, 'message' => 'Access denied for paymentCustomizationCreate field.']],
        ]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Access denied');

        app(PaymentCustomizationInstaller::class)->activate();

        $this->assertDatabaseHas('system_logs', [
            'message' => 'Shopify refused to activate the checkout function',
        ]);
    }
}
