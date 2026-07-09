<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\PaymentLedger;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-time PayMe migration (Phase 3, D-import). Reads the v1 legacy Postgres
 * (connection `v1`) and imports ONLY PayMe subscriptions (iCount is skipped by
 * decision) + their customers, dogs, saved cards, and charge history into the v2
 * schema. Idempotent — upserts by legacy_shopify_gid, safe to re-run. Dry-run by
 * default; pass --apply to write. Every step is logged (channel: stderr on
 * Railway) and summarized.
 */
class ImportFromV1Command extends Command
{
    protected $signature = 'mills:import-from-v1 {--apply : Write changes. Without this flag the command is a dry-run.}';

    protected $description = 'Import PayMe customers/subscriptions/dogs/cards/history from the v1 database (skips iCount).';

    private bool $apply = false;

    /** @var array<string,int> */
    private array $stats = [
        'customers' => 0, 'subscriptions' => 0, 'dogs' => 0, 'payment_methods' => 0, 'ledger' => 0, 'skipped' => 0, 'errors' => 0,
    ];

    public function handle(): int
    {
        $this->apply = (bool) $this->option('apply');
        $mode = $this->apply ? 'APPLY' : 'DRY-RUN';
        $this->info("[import-from-v1] mode={$mode}");
        Log::info('import.v1.start', ['mode' => $mode]);

        try {
            DB::connection('v1')->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot connect to the v1 database. Set V1_DB_URL. '.$e->getMessage());

            return self::FAILURE;
        }

        $subs = DB::connection('v1')->table('shopify_subscriptions')
            ->whereRaw("lower(coalesce(integration_source,'')) = 'payme'")
            ->where('is_deleted', false)
            ->get();

        $this->line("Found {$subs->count()} PayMe subscription(s) in v1.");

        foreach ($subs as $row) {
            $this->importOneSubscription($row);
        }

        $this->importPaymentMethods();
        $this->importLedger();

        $this->newLine();
        $this->info('[import-from-v1] summary ('.$mode.'):');
        foreach ($this->stats as $k => $v) {
            $this->line('  '.str_pad($k, 16).$v);
        }
        Log::info('import.v1.done', ['mode' => $mode, 'stats' => $this->stats]);

        if (! $this->apply) {
            $this->warn('Dry-run only — nothing written. Re-run with --apply to import.');
        }

        return $this->stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function importOneSubscription(object $row): void
    {
        try {
            $payload = $this->decode($row->payload);
            $customerGid = (string) ($row->customer_gid ?: ($payload['customer'] ?? ''));

            $customer = $this->importCustomer($customerGid);
            if ($customer === null) {
                $this->stats['skipped']++;
                $this->warn("  skip subscription {$row->shopify_gid}: no customer");

                return;
            }

            $freq = str_contains(strtolower((string) $row->frequency), '2') ? 2 : 1;
            $nextChargeAt = $this->parseDate((string) $row->charge_cycle);

            $attrs = [
                'customer_id' => $customer->id,
                'payment_state' => PaymentState::PAYME->value,
                'frequency_months' => $freq,
                'next_charge_at' => $nextChargeAt,
                'original_order_id' => $payload['original_order'] ?? null,
                'draft_order_id' => $payload['draft_order_id'] ?? null,
                'meta' => ['imported_from' => 'v1', 'v1_charge_cycle' => $row->charge_cycle],
            ];

            $sub = Subscription::query()->firstOrNew(['legacy_shopify_gid' => (string) $row->shopify_gid]);
            $status = SubscriptionStatus::fromLegacy((string) $row->subscription_status);

            $this->line("  subscription {$row->shopify_gid} -> customer {$customer->id}, {$freq}mo, next={$nextChargeAt}");

            if ($this->apply) {
                $sub->fill($attrs);
                $sub->forceFill(['status' => $status->value, 'legacy_shopify_gid' => (string) $row->shopify_gid])->save();
                $this->stats['subscriptions']++;

                foreach ($this->dogGids($payload) as $dogGid) {
                    $this->importDog($dogGid, $customer->id, $sub->id);
                }
            } else {
                $this->stats['subscriptions']++;
                foreach ($this->dogGids($payload) as $dogGid) {
                    $this->line("    dog {$dogGid}");
                    $this->stats['dogs']++;
                }
            }
        } catch (Throwable $e) {
            $this->stats['errors']++;
            $this->error("  error on {$row->shopify_gid}: ".$e->getMessage());
            Log::error('import.v1.subscription_failed', ['gid' => $row->shopify_gid, 'message' => $e->getMessage()]);
        }
    }

    private function importCustomer(string $customerGid): ?Customer
    {
        if ($customerGid === '') {
            return null;
        }

        $existing = Customer::query()->where('legacy_shopify_gid', $customerGid)->first();
        if ($existing !== null && ! $this->apply) {
            return $existing;
        }

        $row = DB::connection('v1')->table('shopify_customers')->where('shopify_gid', $customerGid)->first();
        if ($row === null) {
            return null;
        }

        $p = $this->decode($row->payload);
        $addr = is_array($p['defaultAddress'] ?? null) ? $p['defaultAddress'] : [];

        $attrs = [
            'shopify_customer_id' => (string) $row->shopify_numeric_id,
            'email' => $row->email ?: ($p['email'] ?? null),
            'first_name' => $p['firstName'] ?? ($addr['firstName'] ?? null),
            'last_name' => $p['lastName'] ?? ($addr['lastName'] ?? null),
            'phone' => $addr['phone'] ?? null,
            'address1' => $addr['address1'] ?? null,
            'address2' => $addr['address2'] ?? null,
            'city' => $addr['city'] ?? null,
            'province' => $addr['province'] ?? null,
            'country' => $addr['country'] ?? null,
            'zip' => $addr['zip'] ?? null,
        ];

        if (! $this->apply) {
            $this->line("  customer {$customerGid} ({$attrs['email']})");
            $this->stats['customers']++;

            return $existing ?? new Customer(['id' => 0] + $attrs);
        }

        $customer = Customer::query()->updateOrCreate(
            ['legacy_shopify_gid' => $customerGid],
            $attrs,
        );
        $this->stats['customers']++;

        return $customer;
    }

    private function importDog(string $dogGid, int $customerId, int $subscriptionId): void
    {
        $row = DB::connection('v1')->table('shopify_dogs')->where('shopify_gid', $dogGid)->first();
        if ($row === null) {
            return;
        }

        $p = $this->decode($row->payload);

        Dog::query()->updateOrCreate(
            ['legacy_shopify_gid' => $dogGid],
            [
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'name' => $row->name ?: ($p['name'] ?? null),
                'sex' => $p['sex'] ?? null,
                'age' => $p['age'] ?? null,
                'weight' => $p['weight'] ?? null,
                'allergies' => $p['allergies'] ?? null,
                'activity' => $p['activity'] ?? null,
                'body' => $p['body'] ?? null,
                'calories_per_day' => $p['calories_per_day'] ?? null,
                'birth_date' => $p['birth_date'] ?? null,
                'double_food' => (bool) ($p['double_food'] ?? false),
                'status' => $row->status ?: 'active',
                'subscription_status' => $row->subscription_status,
            ],
        );
        $this->stats['dogs']++;
    }

    /** Saved PayMe buyer keys from v1 billing_logs card-update rows. */
    private function importPaymentMethods(): void
    {
        $rows = DB::connection('v1')->table('billing_logs')
            ->whereIn('status', ['manual_card_update', 'self_service_card_update'])
            ->whereNotNull('payme_transaction_id')
            ->where('payme_transaction_id', '!=', '')
            ->orderBy('executed_at')
            ->get();

        foreach ($rows as $row) {
            $sub = Subscription::query()->where('legacy_shopify_gid', (string) $row->subscription_shopify_id)->first();
            if ($sub === null) {
                continue; // card-update for an iCount sub we didn't import
            }

            $this->line("  payment_method for customer {$sub->customer_id} (from log #{$row->id})");
            if ($this->apply) {
                PaymentMethod::query()->updateOrCreate(
                    ['customer_id' => $sub->customer_id, 'gateway' => 'payme'],
                    ['buyer_key' => (string) $row->payme_transaction_id, 'is_active' => true, 'source' => 'import', 'captured_at' => $row->executed_at],
                );
            }
            $this->stats['payment_methods']++;
        }
    }

    /** Charge history from v1 billing_logs for imported subscriptions. */
    private function importLedger(): void
    {
        $map = [
            'success' => LedgerStatus::SUCCEEDED, 'succeeded' => LedgerStatus::SUCCEEDED,
            'failed' => LedgerStatus::FAILED, 'error' => LedgerStatus::FAILED,
        ];

        $rows = DB::connection('v1')->table('billing_logs')->orderBy('executed_at')->get();
        foreach ($rows as $row) {
            $status = $map[strtolower((string) $row->status)] ?? null;
            if ($status === null) {
                continue; // card-update rows aren't ledger entries
            }
            $sub = Subscription::query()->where('legacy_shopify_gid', (string) $row->subscription_shopify_id)->first();
            if ($sub === null) {
                continue;
            }

            $key = 'v1-import:log:'.$row->id;
            if ($this->apply) {
                $ledger = PaymentLedger::query()->firstOrNew(['idempotency_key' => $key]);
                $ledger->fill([
                    'subscription_id' => $sub->id,
                    'customer_id' => $sub->customer_id,
                    'context' => 'v1_import',
                    'amount' => $row->amount,
                    'currency' => $row->currency ?: 'ILS',
                    'payme_transaction_id' => $row->payme_transaction_id,
                    'executed_at' => $row->executed_at,
                ]);
                $ledger->forceFill(['status' => $status->value, 'idempotency_key' => $key])->save();
            }
            $this->stats['ledger']++;
        }
    }

    /** @return list<string> */
    private function dogGids(array $payload): array
    {
        $dogs = $payload['dogs'] ?? [];
        if (is_string($dogs)) {
            $decoded = json_decode($dogs, true);
            $dogs = is_array($decoded) ? $decoded : [];
        }

        return array_values(array_filter(array_map('strval', is_array($dogs) ? $dogs : [])));
    }

    /** @return array<string,mixed> */
    private function decode(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function parseDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        try {
            return Carbon::parse($date)->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }
}
