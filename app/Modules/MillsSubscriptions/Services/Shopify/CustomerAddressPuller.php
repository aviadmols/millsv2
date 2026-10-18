<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\Customer;
use App\Models\SystemLog;
use Throwable;

/**
 * Gives a customer who has no address the one Shopify already holds for them.
 *
 * A subscription created from a checkout stored the shopper's name, email and phone — and
 * not the address they had just typed into that very checkout. 63 live customers ended up
 * with none (2026-10-18): their personal area showed an empty address box, and every order
 * this system created for them went out with no shipping address (24 in 30 days), because
 * the local address is what OrderCreationService puts on the order.
 *
 * Only ever FILLS a missing address. An address the customer set through the personal area
 * is the authority (CustomerAddressPusher sends it to Shopify), so an address already here is
 * never overwritten by whatever Shopify happens to hold.
 */
class CustomerAddressPuller
{
    /** The columns an address is made of, mapped from Shopify's snake_case address keys. */
    private const FIELDS = ['address1', 'address2', 'city', 'province', 'country', 'zip'];

    public function __construct(private readonly ShopifyAdminClient $client) {}

    public static function hasAddress(Customer $customer): bool
    {
        return trim((string) $customer->address1) !== '';
    }

    /**
     * From a Shopify order payload: the shipping address the shopper entered, else the
     * customer's default address the order carries. No API call.
     *
     * @param  array<string, mixed>  $order
     */
    public function fromOrder(Customer $customer, array $order): bool
    {
        if (self::hasAddress($customer)) {
            return false;
        }

        foreach (['shipping_address', 'customer.default_address', 'billing_address'] as $path) {
            $address = data_get($order, $path);

            if (is_array($address) && $this->apply($customer, $address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * From the customer's default address in Shopify. Best-effort: a Shopify failure leaves
     * the customer exactly as they were and is logged, never thrown — callers are a page
     * load and an order that has already been paid for.
     */
    public function fromShopify(Customer $customer): bool
    {
        if (self::hasAddress($customer) || empty($customer->shopify_customer_id) || ! $this->client->isConnected()) {
            return false;
        }

        try {
            $result = $this->client->restGet('customers/'.$customer->shopify_customer_id.'.json', [
                'fields' => 'default_address',
            ]);
        } catch (Throwable $e) {
            SystemLog::warning('shopify', 'could not read the customer address from Shopify', [
                'message' => $e->getMessage(),
            ], ['customer_id' => $customer->id]);

            return false;
        }

        $address = data_get($result, 'customer.default_address') ?? data_get($result, 'default_address');

        return is_array($address) && $this->apply($customer, $address);
    }

    /** @param  array<string, mixed>  $address */
    private function apply(Customer $customer, array $address): bool
    {
        // A street and a city or it is not an address worth storing — the same minimum a
        // courier needs, and the one OrderCreationService checks.
        if (trim((string) ($address['address1'] ?? '')) === '' || trim((string) ($address['city'] ?? '')) === '') {
            return false;
        }

        $fill = [];

        foreach (self::FIELDS as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            $fill[$field] = $value !== '' ? $value : null;
        }

        // Name and phone only where we have none: the customer record may know better.
        foreach (['first_name', 'last_name', 'phone'] as $field) {
            $value = trim((string) ($address[$field] ?? ''));
            if (trim((string) $customer->{$field}) === '' && $value !== '') {
                $fill[$field] = $value;
            }
        }

        $customer->forceFill($fill)->save();

        SystemLog::info('shopify', 'customer address filled from Shopify', [
            'city' => $fill['city'],
        ], ['customer_id' => $customer->id]);

        return true;
    }
}
