<?php

namespace App\Filament\Widgets;

use App\Http\Controllers\Api\CronApiController;
use App\Models\CronRun;
use App\Models\PaymentLedger;
use App\Models\ShopifyConnection;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Is the machine actually running?
 *
 * Every one of these has already been silently false at least once in this system's life:
 * the scheduler was never deployed, so billing simply never ran; the Shopify token was
 * revoked, so nothing synced; the charge amount was unknown, so every charge aborted. None
 * of it announced itself — the admin looked at a normal-looking screen while nothing worked.
 *
 * So this panel is deliberately blunt. It states when the biller last ran, not merely that
 * it is "enabled"; a switch that is on and a job that is running are not the same thing, and
 * conflating them is exactly how v1 went months without charging anyone.
 */
class SystemHealth extends Widget
{
    protected static ?int $sort = 0;   // above everything — if this is red, nothing else matters

    protected string $view = 'filament.widgets.system-health';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'checks' => [
                $this->billingRan(),
                $this->queueDrained(),
                $this->tooFarBehind(),
                $this->stuckPayments(),
                $this->shopify(),
                $this->payme(),
                $this->sms(),
            ],
        ];
    }

    /**
     * Is anything actually PERFORMING the charges?
     *
     * `mills:dispatch-due` does not charge anyone — it queues a ChargeSubscriptionJob and
     * returns. Without a queue worker draining that queue, the scheduler runs happily every
     * five minutes, the jobs pile up in a database table, and not one customer is billed —
     * while the check above reports, truthfully, that billing "ran 2 minutes ago".
     *
     * A green CRON light over a dead worker is the most dangerous screen this app could show,
     * so the worker gets its own line.
     *
     * @return array<string, mixed>
     */
    private function queueDrained(): array
    {
        // A job sitting in the queue for more than a few minutes means nobody is taking it.
        $stale = DB::table('jobs')
            ->where('created_at', '<=', now()->subMinutes(10)->getTimestamp())
            ->count();

        $waiting = DB::table('jobs')->count();
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        if ($stale > 0) {
            return $this->check(
                __('dashboard.health_worker'),
                'critical',
                __('dashboard.health_worker_stuck', ['count' => $stale]),
                __('dashboard.health_worker_stuck_help'),
            );
        }

        if ($failed > 0) {
            return $this->check(
                __('dashboard.health_worker'),
                'warning',
                __('dashboard.health_worker_failed', ['count' => $failed]),
                __('dashboard.health_worker_failed_help'),
            );
        }

        return $this->check(
            __('dashboard.health_worker'),
            'ok',
            __('dashboard.health_worker_ok', ['count' => $waiting]),
        );
    }

    /**
     * The one that matters most: not "is billing enabled" but "did it actually run".
     *
     * @return array<string, mixed>
     */
    private function billingRan(): array
    {
        // Read through the safe parser, NOT the raw cache: a Carbon serialized by the
        // scheduler container came back to the web container as an incomplete object and threw
        // the moment this widget touched it — taking the entire dashboard down with it.
        $lastRun = CronApiController::lastDispatchAt();
        $enabled = CronApiController::isEnabled();

        if ($lastRun === null) {
            return $this->check(
                __('dashboard.health_billing'),
                'critical',
                __('dashboard.health_billing_never'),
                __('dashboard.health_billing_never_help'),
            );
        }

        $minutes = $lastRun->diffInMinutes(now());

        // It is scheduled every 5 minutes. Twenty minutes of silence means it is not running,
        // whatever the switch says.
        $status = match (true) {
            $minutes > 20 => 'critical',
            $minutes > 10 => 'warning',
            ! $enabled => 'warning',
            default => 'ok',
        };

        return $this->check(
            __('dashboard.health_billing'),
            $status,
            $enabled
                ? __('dashboard.health_billing_ran', ['when' => $lastRun->diffForHumans()])
                : __('dashboard.health_billing_off'),
            __('dashboard.health_billing_at', ['time' => $lastRun->format('Y-m-d H:i')]),
        );
    }

    /**
     * Subscriptions the biller has deliberately refused to charge.
     *
     * They are more than a whole cycle behind, so charging them automatically would bill the
     * customer for months of boxes that were never shipped — one cycle every five minutes
     * until it caught up. They wait here instead, and someone has to decide.
     *
     * Silence would be the worst outcome: a subscription that quietly stops being billed and
     * never says so is indistinguishable from one that is being billed.
     *
     * @return array<string, mixed>
     */
    private function tooFarBehind(): array
    {
        $count = Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_state', PaymentState::PAYME->value)
            ->tooFarBehind()
            ->count();

        if ($count === 0) {
            return $this->check(__('dashboard.health_behind'), 'ok', __('dashboard.health_behind_ok'));
        }

        return $this->check(
            __('dashboard.health_behind'),
            'warning',
            __('dashboard.health_behind_count', ['count' => $count]),
            __('dashboard.health_behind_help'),
        );
    }

    /**
     * Charges whose outcome we never learned. Each one is real money in limbo, and each one
     * blocks its subscription from being charged at all until it is resolved.
     *
     * @return array<string, mixed>
     */
    private function stuckPayments(): array
    {
        $stuck = PaymentLedger::query()
            ->where('status', LedgerStatus::PENDING->value)
            ->where('created_at', '<=', now()->subMinutes(15))
            ->count();

        if ($stuck === 0) {
            return $this->check(__('dashboard.health_payments'), 'ok', __('dashboard.health_payments_ok'));
        }

        return $this->check(
            __('dashboard.health_payments'),
            'critical',
            __('dashboard.health_payments_stuck', ['count' => $stuck]),
            __('dashboard.health_payments_stuck_help'),
        );
    }

    /** @return array<string, mixed> */
    private function shopify(): array
    {
        $connection = ShopifyConnection::current();

        return $connection?->isConnected()
            ? $this->check(__('dashboard.health_shopify'), 'ok', $connection->shop_domain)
            : $this->check(
                __('dashboard.health_shopify'),
                'critical',
                __('dashboard.health_shopify_off'),
                __('dashboard.health_shopify_off_help'),
            );
    }

    /** @return array<string, mixed> */
    private function payme(): array
    {
        $configured = filled(config('payme.api_url')) && filled(config('payme.seller_id'));

        return $configured
            ? $this->check(__('dashboard.health_payme'), 'ok', __('dashboard.health_configured'))
            : $this->check(
                __('dashboard.health_payme'),
                'critical',
                __('dashboard.health_not_configured'),
                __('dashboard.health_payme_help'),
            );
    }

    /** @return array<string, mixed> */
    private function sms(): array
    {
        $configured = filled(config('sms.019.username')) && filled(config('sms.019.token'));

        // Not critical: SMS only gates the personal-area login, not the money.
        return $configured
            ? $this->check(__('dashboard.health_sms'), 'ok', __('dashboard.health_configured'))
            : $this->check(
                __('dashboard.health_sms'),
                'warning',
                __('dashboard.health_not_configured'),
                __('dashboard.health_sms_help'),
            );
    }

    /** @return array<string, mixed> */
    private function check(string $label, string $status, string $value, ?string $help = null): array
    {
        return compact('label', 'status', 'value', 'help');
    }

    /** The last scheduled runs, for the "when did it last run" question. */
    public function getRecentRuns(): array
    {
        return CronRun::query()
            ->orderByDesc('ran_at')
            ->limit(5)
            ->get()
            ->map(fn (CronRun $run) => [
                'command' => $run->command,
                'status' => $run->status,
                'ran_at' => $run->ran_at?->format('Y-m-d H:i:s'),
                'ago' => $run->ran_at?->diffForHumans(),
                'runtime_ms' => $run->runtime_ms,
            ])
            ->all();
    }
}
