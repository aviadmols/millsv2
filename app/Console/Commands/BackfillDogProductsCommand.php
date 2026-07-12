<?php

namespace App\Console\Commands;

use App\Models\Dog;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill each dog's PRODUCTS from its Shopify `dog` metaobject.
 *
 * Why this exists: v1 mirrored dogs into Postgres but its mirror never carried
 * `selected_variants` / `addons_products` — those were read live from the
 * metaobject on every request (SYSTEM-MAP §2, known issue #4). So the v1 import
 * brought the dogs across with NULL products, and a subscription looks empty even
 * though the customer really does have flavours and add-ons.
 *
 * The metaobjects still exist in the store, so this reads them once and writes the
 * variant ids into the local dog rows. After that the DB is the source of truth and
 * this command is never needed again.
 *
 * Requires a live Shopify connection. Dry-run by default; pass --apply to write.
 */
class BackfillDogProductsCommand extends Command
{
    protected $signature = 'mills:backfill-dog-products {--apply : Write the changes (otherwise dry-run)}';

    protected $description = "Pull each dog's selected variants + add-ons from its Shopify metaobject into the local DB.";

    private const QUERY = <<<'GQL'
    query($id: ID!) {
      metaobject(id: $id) {
        id
        fields { key value }
      }
    }
    GQL;

    public function handle(ShopifyAdminClient $shopify): int
    {
        if (! $shopify->isConnected()) {
            $this->error('Shopify is not connected — reconnect the app first (Settings → Connect Shopify).');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info('[backfill-dog-products] mode='.($apply ? 'APPLY' : 'DRY-RUN'));

        $dogs = Dog::query()->whereNotNull('legacy_shopify_gid')->get();
        $this->line("{$dogs->count()} imported dog(s) to check.");

        $filled = 0;
        $empty = 0;
        $failed = 0;

        foreach ($dogs as $dog) {
            try {
                $result = $shopify->graphql(self::QUERY, ['id' => $dog->legacy_shopify_gid]);
                $fields = $result['data']['metaobject']['fields'] ?? null;

                if ($fields === null) {
                    $this->warn("  dog #{$dog->id} ({$dog->name}): metaobject not found in Shopify");
                    $failed++;

                    continue;
                }

                $selected = $this->variants($fields, ['selected_variants', 'subscription_products']);
                $addons = $this->variants($fields, ['addons_products']);

                if ($selected === [] && $addons === []) {
                    $this->line("  dog #{$dog->id} ({$dog->name}): no products on the metaobject");
                    $empty++;

                    continue;
                }

                $this->info("  dog #{$dog->id} ({$dog->name}): ".count($selected).' variant(s), '.count($addons).' add-on(s)');

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
        $this->info("filled={$filled}  no_products={$empty}  failed={$failed}");

        SystemLog::info('shopify', 'dog products backfilled from Shopify metaobjects', [
            'mode' => $apply ? 'apply' : 'dry-run',
            'filled' => $filled,
            'no_products' => $empty,
            'failed' => $failed,
        ]);

        if (! $apply) {
            $this->warn('Dry-run — nothing written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Metaobject list fields come back as a JSON-encoded string of variant GIDs.
     *
     * @param  list<array{key: string, value: string|null}>  $fields
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function variants(array $fields, array $keys): array
    {
        $out = [];

        foreach ($fields as $field) {
            if (! in_array($field['key'] ?? '', $keys, true)) {
                continue;
            }

            $value = $field['value'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $decoded = json_decode((string) $value, true);
            $items = is_array($decoded) ? $decoded : [$value];

            foreach ($items as $item) {
                if (is_array($item)) {
                    $item = $item['id'] ?? null;
                }
                if ($item !== null && $item !== '') {
                    $out[] = (string) $item;
                }
            }
        }

        return array_values(array_unique($out));
    }
}
