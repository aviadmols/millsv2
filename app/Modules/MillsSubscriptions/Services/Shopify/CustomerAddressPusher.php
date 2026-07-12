<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\Customer;
use App\Models\SystemLog;
use App\Support\ShopifyId;
use Throwable;

/**
 * Pushes the customer's address back to Shopify so shipping labels are right
 * (D-address). The LOCAL DB is the source of truth: it is written first, and a
 * Shopify failure is logged but NEVER blocks or reverts the local write.
 */
class CustomerAddressPusher
{
    private const DEFAULT_ADDRESS_QUERY = <<<'GQL'
    query($id: ID!) {
      customer(id: $id) { defaultAddress { id } }
    }
    GQL;

    private const UPDATE_MUTATION = <<<'GQL'
    mutation($addressId: ID!, $address: MailingAddressInput!) {
      customerAddressUpdate(addressId: $addressId, address: $address, setAsDefault: true) {
        userErrors { field message }
      }
    }
    GQL;

    private const CREATE_MUTATION = <<<'GQL'
    mutation($customerId: ID!, $address: MailingAddressInput!) {
      customerAddressCreate(customerId: $customerId, address: $address, setAsDefault: true) {
        userErrors { field message }
      }
    }
    GQL;

    public function __construct(private readonly ShopifyAdminClient $client) {}

    /** @return bool true when Shopify accepted the address */
    public function push(Customer $customer): bool
    {
        if (! $this->client->isConnected() || empty($customer->shopify_customer_id)) {
            return false;
        }

        $customerGid = ShopifyId::gid((string) $customer->shopify_customer_id, 'Customer');

        $address = array_filter([
            'firstName' => $customer->first_name,
            'lastName' => $customer->last_name,
            'address1' => $customer->address1,
            'address2' => $customer->address2,
            'city' => $customer->city,
            'province' => $customer->province,
            'country' => $customer->country,
            'zip' => $customer->zip,
            'phone' => $customer->phone,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $existing = $this->client->graphql(self::DEFAULT_ADDRESS_QUERY, ['id' => $customerGid]);
            $addressId = $existing['data']['customer']['defaultAddress']['id'] ?? null;

            $result = $addressId !== null
                ? $this->client->graphql(self::UPDATE_MUTATION, ['addressId' => $addressId, 'address' => $address])
                : $this->client->graphql(self::CREATE_MUTATION, ['customerId' => $customerGid, 'address' => $address]);

            $errors = $result['data']['customerAddressUpdate']['userErrors']
                ?? $result['data']['customerAddressCreate']['userErrors']
                ?? ($result['errors'] ?? []);

            if (! empty($errors)) {
                SystemLog::warning('shopify', 'address push rejected by Shopify', [
                    'errors' => $errors,
                ], ['customer_id' => $customer->id]);

                return false;
            }

            $customer->forceFill(['address_pushed_at' => now()])->save();

            SystemLog::info('shopify', 'address pushed to Shopify', [], ['customer_id' => $customer->id]);

            return true;
        } catch (Throwable $e) {
            // Compensating, logged — the local address stays authoritative.
            SystemLog::error('shopify', 'address push failed', [
                'message' => $e->getMessage(),
            ], ['customer_id' => $customer->id]);

            return false;
        }
    }
}
