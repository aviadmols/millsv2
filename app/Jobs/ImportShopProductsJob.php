<?php

namespace App\Jobs;

use App\Modules\MillsSubscriptions\Services\Shopify\ProductSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportShopProductsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(ProductSyncService $products): void
    {
        $products->refreshAll();
    }
}
