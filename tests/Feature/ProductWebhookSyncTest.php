<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Jobs\ImportShopProductsJob;
use App\Jobs\ProcessShopifyWebhookJob;
use App\Models\Product;
use App\Models\ShopifyConnection;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Modules\MillsSubscriptions\Services\PaidOrderIngestor;
use App\Modules\MillsSubscriptions\Services\Shopify\ProductSyncService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopInstaller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A `products/*` webhook names ONE product, and that is all it should cost.
 *
 * It used to re-read the entire catalogue for every price tweak: 20+ seconds of Shopify
 * calls per webhook, which is long enough that an ordinary network blip failed the job and
 * left the cache stale anyway. Two of those landed in failed_jobs in a single day.
 */
class ProductWebhookSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ShopifyConnection::query()->create([
            'shop_domain' => 'millsforpets.myshopify.com',
            'access_token' => 'shpat_test',
            'installed_at' => now(),
        ]);
    }

    /** @param array<string, mixed>|null $product */
    private function fakeProduct(?array $product): void
    {
        Http::fake([
            '*/graphql.json' => Http::response(['data' => ['product' => $product]]),
        ]);
    }

    public function test_one_webhook_reads_one_product(): void
    {
        $this->fakeProduct([
            'id' => 'gid://shopify/Product/8499033800792',
            'title' => 'Salmon 30',
            'handle' => 'salmon-30',
            'status' => 'ACTIVE',
            'tags' => [],
            'variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/4242', 'title' => '79g', 'sku' => 'SF30 - 79', 'price' => '171.00', 'availableForSale' => true],
            ]],
        ]);

        $refreshed = app(ProductSyncService::class)->refreshOne('8499033800792');

        $this->assertTrue($refreshed);
        $this->assertDatabaseHas('products', ['shopify_product_id' => '8499033800792', 'title' => 'Salmon 30']);
        $this->assertDatabaseHas('product_variants', ['shopify_variant_id' => '4242', 'grams' => 79]);

        // ONE call, and it asked for this product by id — not a paged sweep of the catalogue.
        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return str_contains((string) ($request['variables']['id'] ?? ''), '8499033800792')
                && ! str_contains((string) ($request['query'] ?? ''), 'products(first:');
        });
    }

    public function test_a_product_with_more_variants_than_one_page_syncs_them_all(): void
    {
        /*
         * The hidden pricing product grows a variant per flavor×size×price and sailed past
         * the old fixed cap — every variant beyond it was invisible to the cache, which is
         * how a real dog's food rendered as "וריאנט לא מזוהה" on the subscription screen.
         */
        Http::fake([
            '*/graphql.json' => Http::sequence()
                ->push(['data' => ['product' => [
                    'id' => 'gid://shopify/Product/777',
                    'title' => 'מנוי מתחדש',
                    'handle' => 'subs-test',
                    'status' => 'ACTIVE',
                    'variants' => [
                        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cur-1'],
                        'nodes' => [
                            ['id' => 'gid://shopify/ProductVariant/1001', 'title' => 'a', 'sku' => 'SF30 - 79', 'price' => '171.00', 'availableForSale' => true],
                        ],
                    ],
                ]]])
                ->push(['data' => ['product' => ['variants' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'nodes' => [
                        ['id' => 'gid://shopify/ProductVariant/66514429215024', 'title' => 'b', 'sku' => 'TB30 - 84', 'price' => '162.00', 'availableForSale' => true],
                    ],
                ]]]]),
        ]);

        $this->assertTrue(app(ProductSyncService::class)->refreshOne('777'));

        // The variant from the SECOND page is in the cache — the one that used to vanish.
        $this->assertDatabaseHas('product_variants', ['shopify_variant_id' => '66514429215024', 'grams' => 84]);
        $this->assertDatabaseHas('product_variants', ['shopify_variant_id' => '1001']);
        Http::assertSentCount(2);
    }

    public function test_the_admin_sync_button_queues_the_sweep_instead_of_running_it_in_the_request(): void
    {
        /*
         * A full sweep walks every product and every page of its variants — minutes of
         * Shopify calls. Running that inside the browser request made the button hang
         * (27 Aug), with no way to tell a slow sync from a broken one.
         */
        Queue::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(ListProducts::class)
            ->mountAction('syncFromShopify')
            ->callMountedAction();

        Queue::assertPushed(ImportShopProductsJob::class);
    }

    public function test_a_product_deleted_before_we_read_it_is_not_an_error(): void
    {
        // Shopify answers `product: null` for an id it no longer has. Nothing to cache and
        // nothing anyone can do about it, so the job must not throw and retry three times.
        $this->fakeProduct(null);

        $this->assertFalse(app(ProductSyncService::class)->refreshOne('999999'));
        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_webhook_job_refreshes_only_the_product_it_names(): void
    {
        $this->fakeProduct([
            'id' => 'gid://shopify/Product/555',
            'title' => 'Beef 15',
            'handle' => 'beef-15',
            'status' => 'ACTIVE',
            'variants' => ['nodes' => []],
        ]);

        $event = WebhookEvent::query()->create([
            'webhook_id' => 'wh-products-1',
            'topic' => 'products/update',
            'payload' => ['id' => 555, 'title' => 'Beef 15'],
            'status' => 'received',
        ]);

        app(ProcessShopifyWebhookJob::class, ['webhookEventId' => $event->id])
            ->handle(
                app(ProductSyncService::class),
                app(ShopInstaller::class),
                app(PaidOrderIngestor::class),
            );

        $this->assertDatabaseHas('products', ['shopify_product_id' => '555']);
        Http::assertSentCount(1);
    }
}
