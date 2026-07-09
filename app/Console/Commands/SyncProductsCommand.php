<?php

namespace App\Console\Commands;

use App\Modules\MillsSubscriptions\Services\Shopify\ProductSyncService;
use Illuminate\Console\Command;

/**
 * Pull products + variants (incl. featured image URLs) from Shopify into the
 * local cache (ARCHITECTURE.md §1c). Run on install, nightly, or on demand.
 */
class SyncProductsCommand extends Command
{
    protected $signature = 'mills:sync-products';

    protected $description = 'Sync products and images from the Shopify store into the local cache.';

    public function handle(ProductSyncService $products): int
    {
        $this->info('Syncing products from Shopify…');
        $count = $products->refreshAll();

        if ($count === 0) {
            $this->warn('0 products synced — is the Shopify app connected (shopify_connection token)?');

            return self::SUCCESS;
        }

        $this->info("Synced {$count} product(s).");

        return self::SUCCESS;
    }
}
