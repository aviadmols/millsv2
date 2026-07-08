<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Jobs\ImportShopProductsJob;
use App\Jobs\RegisterShopifyWebhooksJob;
use App\Models\ShopifyConnection;
use Illuminate\Support\Facades\Log;

/**
 * Persists the OAuth result into the single `shopify_connection` record and kicks
 * off post-install work (webhook registration + product backfill). Idempotent —
 * a reinstall reuses the row and refreshes the token.
 */
class ShopInstaller
{
    public function install(string $shop, string $accessToken, string $scope): ShopifyConnection
    {
        $connection = ShopifyConnection::query()->first() ?? new ShopifyConnection;

        $connection->shop_domain = $shop;
        $connection->access_token = $accessToken;
        $connection->scopes = array_values(array_filter(array_map('trim', explode(',', $scope))));
        $connection->installed_at = now();
        $connection->uninstalled_at = null;
        $connection->save();

        Log::info('shopify.installed', ['shop' => $shop]);

        RegisterShopifyWebhooksJob::dispatch();
        ImportShopProductsJob::dispatch();

        return $connection;
    }

    public function markUninstalled(): void
    {
        $connection = ShopifyConnection::query()->first();
        if ($connection === null) {
            return;
        }

        $connection->access_token = null;
        $connection->uninstalled_at = now();
        $connection->save();

        Log::warning('shopify.uninstalled', ['shop' => $connection->shop_domain]);
    }
}
