<?php

namespace App\Console\Commands;

use App\Models\Dog;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderHistoryService;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use Illuminate\Console\Command;
use Throwable;

/**
 * Repair the imported dogs' product selection.
 *
 * The v1 import brought every dog across with NULL selected_variants — not a bug in
 * the import: v1's local mirror never carried that field. It read the products live
 * from the Shopify `dog` metaobject on every request, so the data only ever existed
 * in Shopify.
 *
 * The obvious fix — read the metaobjects — does not work: the app's granted scopes
 * don't include metaobject access, and the query comes back null. So we use the
 * source that IS readable and is arguably more truthful anyway: the customer's most
 * recent PAID order. Whatever Shopify actually shipped them is, by definition, what
 * their subscription contains.
 *
 * Dry-run by default. Once applied, the DB is the source of truth and this command
 * is never needed again.
 */
class BackfillDogProductsCommand extends Command
{
    protected $signature = 'mills:backfill-dog-products {--apply : Write the changes (otherwise dry-run)}';

    protected $description = "Fill each dog's products from the customer's most recent paid Shopify order.";

    public function handle(OrderHistoryService $orders): int
    {
        $apply = (bool) $this->option('apply');
        $this->info('[backfill-dog-products] mode='.($apply ? 'APPLY' : 'DRY-RUN'));

        // Only dogs that are actually missing their products. (Postgres will not
        // compare a json column to a string, hence the explicit ::text cast.)
        $dogs = Dog::query()
            ->with('customer')
            ->where(fn ($q) => $q->whereNull('selected_variants')
                ->orWhereRaw("selected_variants::text = '[]'"))
            ->get();

        $this->line("{$dogs->count()} dog(s) with no products.");

        $filled = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($dogs as $dog) {
            $customer = $dog->customer;

            if ($customer === null) {
                $this->warn("  dog #{$dog->id}: no customer");
                $skipped++;

                continue;
            }

            try {
                $variantIds = $orders->latestPaidVariantIds($customer);

                if ($variantIds === []) {
                    $this->line("  dog #{$dog->id} ({$dog->name}): no paid order to learn from");
                    $skipped++;

                    continue;
                }

                // Only the portioned subscription variants are the dog's food; anything
                // else on that order (treats, accessories) is an add-on, not a flavour.
                $variants = VariantResolver::resolve($variantIds);
                $food = $variants->filter(fn ($v) => $v->grams !== null);
                $extras = $variants->filter(fn ($v) => $v->grams === null);

                if ($food->isEmpty()) {
                    $this->line("  dog #{$dog->id} ({$dog->name}): the last order had no portioned food");
                    $skipped++;

                    continue;
                }

                $selected = $food->pluck('shopify_variant_id')->map('strval')->values()->all();
                $addons = $extras->pluck('shopify_variant_id')->map('strval')->values()->all();

                $this->info("  dog #{$dog->id} ({$dog->name}): ".implode(', ', $food->map(
                    fn ($v) => ($v->product?->title ?? '?').' '.$v->grams.'g'
                )->all()));

                if ($apply) {
                    $dog->forceFill([
                        'selected_variants' => $selected,
                        'addons_products' => $addons,
                    ])->save();
                }

                $filled++;
            } catch (Throwable $e) {
                $this->error("  dog #{$dog->id}: ".$e->getMessage());
                $failed++;
            }
        }

        $this->newLine();
        $this->info("filled={$filled}  skipped={$skipped}  failed={$failed}");

        SystemLog::info('shopify', 'dog products backfilled from paid orders', [
            'mode' => $apply ? 'apply' : 'dry-run',
            'filled' => $filled,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        if (! $apply) {
            $this->warn('Dry-run — nothing written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }
}
