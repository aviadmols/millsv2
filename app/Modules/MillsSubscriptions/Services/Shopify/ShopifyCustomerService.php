<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\SystemLog;
use App\Support\ShopifyId;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Look up customers in Shopify — by search, or by id.
 *
 * Neither v1 nor v2 ever had this: customers only ever arrived by sync or by webhook, so
 * there was no way to go and FETCH one. Adding a customer who exists in the store but not
 * here — the entire iCount population — needs it.
 *
 * `present()` deliberately flattens Shopify's camelCase GraphQL into the snake_case REST shape
 * the customer webhooks already deliver, so `CustomerMapper` consumes it unchanged. A
 * camelCase shape here would force a second mapping, and a second mapping is a field waiting
 * to be forgotten.
 */
class ShopifyCustomerService
{
    private const SEARCH_TTL_SECONDS = 60;    // search results go stale fast

    private const FIND_TTL_SECONDS = 300;

    private const FIELDS = <<<'GQL'
    id
    email
    phone
    firstName
    lastName
    note
    defaultAddress {
      firstName
      lastName
      phone
      address1
      address2
      city
      province
      country
      zip
    }
    GQL;

    private const SEARCH = <<<'GQL'
    query($q: String!, $first: Int!) {
      customers(first: $first, query: $q) {
        nodes { %FIELDS% }
      }
    }
    GQL;

    private const FIND = <<<'GQL'
    query($id: ID!) {
      customer(id: $id) { %FIELDS% }
    }
    GQL;

    public function __construct(private readonly ShopifyAdminClient $client) {}

    /**
     * @return list<array<string, mixed>> empty when disconnected, blank, or Shopify fails
     */
    public function search(string $term, int $limit = 20): array
    {
        $term = trim($term);

        if ($term === '' || ! $this->client->isConnected()) {
            return [];
        }

        return Cache::remember(
            'shopify.customer.search.'.md5($term.'|'.$limit),
            self::SEARCH_TTL_SECONDS,
            function () use ($term, $limit) {
                try {
                    $result = $this->client->graphql($this->query(self::SEARCH), [
                        'q' => $term,
                        'first' => $limit,
                    ]);

                    $nodes = $result['data']['customers']['nodes'] ?? null;

                    if (! is_array($nodes)) {
                        SystemLog::warning('shopify', 'customer search returned nothing', [
                            'errors' => $result['errors'] ?? null,
                        ]);

                        return [];
                    }

                    return array_map(fn (array $node) => $this->present($node), $nodes);
                } catch (Throwable $e) {
                    SystemLog::error('shopify', 'customer search failed', [
                        'message' => $e->getMessage(),
                    ]);

                    return [];
                }
            },
        );
    }

    /**
     * @return array<string, mixed> empty when not found or unreachable
     */
    public function find(string $idOrGid): array
    {
        $numeric = ShopifyId::numeric($idOrGid);

        if ($numeric === '' || ! $this->client->isConnected()) {
            return [];
        }

        return Cache::remember(
            "shopify.customer.{$numeric}",
            self::FIND_TTL_SECONDS,
            function () use ($numeric) {
                try {
                    $result = $this->client->graphql($this->query(self::FIND), [
                        'id' => ShopifyId::gid($numeric, 'Customer'),
                    ]);

                    $node = $result['data']['customer'] ?? null;

                    return is_array($node) ? $this->present($node) : [];
                } catch (Throwable $e) {
                    SystemLog::error('shopify', 'customer fetch failed', [
                        'message' => $e->getMessage(),
                        'shopify_customer_id' => $numeric,
                    ]);

                    return [];
                }
            },
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed> the REST shape CustomerMapper reads
     */
    private function present(array $node): array
    {
        $address = (array) ($node['defaultAddress'] ?? []);

        return [
            'id' => ShopifyId::numeric((string) ($node['id'] ?? '')),
            'email' => $node['email'] ?? null,
            'phone' => $node['phone'] ?? null,
            'first_name' => $node['firstName'] ?? null,
            'last_name' => $node['lastName'] ?? null,
            'note' => (string) ($node['note'] ?? ''),
            'default_address' => [
                'first_name' => $address['firstName'] ?? null,
                'last_name' => $address['lastName'] ?? null,
                'phone' => $address['phone'] ?? null,
                'address1' => $address['address1'] ?? null,
                'address2' => $address['address2'] ?? null,
                'city' => $address['city'] ?? null,
                'province' => $address['province'] ?? null,
                'country' => $address['country'] ?? null,
                'zip' => $address['zip'] ?? null,
            ],
        ];
    }

    private function query(string $template): string
    {
        return str_replace('%FIELDS%', self::FIELDS, $template);
    }
}
