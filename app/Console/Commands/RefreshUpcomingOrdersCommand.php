<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rebuild the upcoming Shopify draft for every live subscription, and with it the amount
 * that will actually be charged.
 *
 * `next_charge_amount` is a STORED copy of the draft's total, refreshed only when something
 * touches that subscription — after a charge, when a dog changes, when an admin opens the
 * screen. So a change to how the draft is priced (the subscriber discount, a product price
 * in Shopify) does not reach anybody until their draft is rebuilt: they keep being billed
 * the old figure for another full cycle, while the dashboard shows the old figure too.
 *
 * This is the command that makes such a change take effect now, deliberately and visibly,
 * rather than leaking in one customer at a time. It only ever REBUILDS what Shopify says
 * the order costs — it never invents a price.
 */
class RefreshUpcomingOrdersCommand extends Command
{
    protected $signature = 'mills:refresh-upcoming-orders
        {--limit=0 : Maximum subscriptions to touch (0 = all)}
        {--dry-run : List what would be rebuilt, and what it currently says, without calling Shopify}';

    protected $description = 'Rebuild each subscription\'s upcoming order so the stored charge amount matches what Shopify now prices it at.';

    public function handle(DraftOrderService $drafts): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));

        $query = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::PAUSED->value])
            ->whereNotNull('draft_order_id')
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No subscriptions with an upcoming order to rebuild.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'Would rebuild ' : 'Rebuilding ').$total.' upcoming orders.');

        $changed = 0;
        $failed = 0;
        $unchanged = 0;

        foreach ($query->cursor() as $subscription) {
            $before = $subscription->next_charge_amount;

            if ($dryRun) {
                $this->line(sprintf('  #%d — currently ₪%s', $subscription->id, $before ?? '—'));

                continue;
            }

            try {
                $drafts->refresh($subscription);
            } catch (Throwable $e) {
                $failed++;
                $this->warn(sprintf('  #%d — failed: %s', $subscription->id, $e->getMessage()));

                continue;
            }

            $after = $subscription->fresh()?->next_charge_amount;

            if ((float) $before === (float) $after) {
                $unchanged++;

                continue;
            }

            $changed++;
            $this->line(sprintf('  #%d — ₪%s → ₪%s', $subscription->id, $before ?? '—', $after ?? '—'));
        }

        if ($dryRun) {
            return self::SUCCESS;
        }

        // A sweep that silently repriced hundreds of customers would be indistinguishable
        // from one that did nothing; the tally belongs in the log the admin can read.
        SystemLog::info('billing', 'upcoming orders rebuilt', [
            'changed' => $changed,
            'unchanged' => $unchanged,
            'failed' => $failed,
        ]);

        $this->info(sprintf('Done. %d repriced, %d unchanged, %d failed.', $changed, $unchanged, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
