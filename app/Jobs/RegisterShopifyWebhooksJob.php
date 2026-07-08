<?php

namespace App\Jobs;

use App\Modules\MillsSubscriptions\Services\Shopify\WebhookRegistrar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RegisterShopifyWebhooksJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function handle(WebhookRegistrar $registrar): void
    {
        $registrar->registerAll();
    }
}
