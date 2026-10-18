<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\CustomerAddressPuller;
use Illuminate\Console\Command;

/**
 * One pass over the customers who have a live subscription and no address, giving each the
 * default address Shopify holds (CustomerAddressPuller). The personal area and the order
 * path already do this one customer at a time; this heals the backlog without waiting for
 * each of them to log in or be charged.
 */
class PullCustomerAddressesCommand extends Command
{
    protected $signature = 'mills:pull-customer-addresses {--dry-run : list who would be filled, change nothing}';

    protected $description = 'Fill missing customer addresses from their Shopify default address';

    public function handle(CustomerAddressPuller $puller): int
    {
        $customers = Customer::query()
            ->whereHas('subscriptions', fn ($q) => $q->where('status', '!=', SubscriptionStatus::CANCELLED->value))
            ->where(fn ($q) => $q->whereNull('address1')->orWhere('address1', ''))
            ->whereNotNull('shopify_customer_id')
            ->orderBy('id')
            ->get();

        $this->info("{$customers->count()} customers with a live subscription and no address.");

        if ($this->option('dry-run')) {
            foreach ($customers as $customer) {
                $this->line("  {$customer->id}  {$customer->email}");
            }

            return self::SUCCESS;
        }

        $filled = 0;

        foreach ($customers as $customer) {
            if ($puller->fromShopify($customer)) {
                $filled++;
                $this->line("  filled {$customer->id}  {$customer->city}");
            } else {
                $this->warn("  none   {$customer->id}  {$customer->email} — Shopify has no usable address");
            }
        }

        $this->info("Filled {$filled} of {$customers->count()}.");

        return self::SUCCESS;
    }
}
