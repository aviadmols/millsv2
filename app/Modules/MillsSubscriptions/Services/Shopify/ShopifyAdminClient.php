<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\ShopifyConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Single-store Admin API client (ARCHITECTURE.md §1b). The offline token is read
 * from the encrypted `shopify_connection` record; the API version is pinned in
 * config. 429/THROTTLED are retried with backoff. No per-shop factory (single
 * tenant).
 */
class ShopifyAdminClient
{
    private ?ShopifyConnection $connection;

    public function __construct(?ShopifyConnection $connection = null)
    {
        $this->connection = $connection ?? ShopifyConnection::current();
    }

    public function isConnected(): bool
    {
        return $this->connection?->isConnected() ?? false;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        return $this->http()->post('/graphql.json', [
            'query' => $query,
            'variables' => $variables,
        ])->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function restGet(string $path, array $query = []): array
    {
        return $this->http()->get(ltrim($path, '/'), $query)->json() ?? [];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function restPost(string $path, array $body): array
    {
        return $this->http()->post(ltrim($path, '/'), $body)->json() ?? [];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withHeaders([
                'X-Shopify-Access-Token' => (string) ($this->connection?->access_token ?? ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->timeout(30)
            ->retry(3, 300, fn ($exception, $request) => optional($exception->response ?? null)->status() === 429, throw: false);
    }

    private function baseUrl(): string
    {
        $domain = (string) ($this->connection?->shop_domain ?? '');
        $version = (string) config('shopify.api_version');

        return "https://{$domain}/admin/api/{$version}";
    }
}
