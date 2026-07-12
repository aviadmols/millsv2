<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\CronRun;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Billing-cron control + observability (SYSTEM-MAP §3.1), at /api/cron/* and the
 * legacy /order/cron/* aliases.
 *
 * v2 does NOT reintroduce v1's fatal design (a cache flag that silently defaulted
 * to OFF and a single one-minute window with no catch-up). Here the scheduler
 * always runs every 5 minutes with catch-up on a dedicated service; start/stop
 * flips a DURABLE, DB-backed switch, and every skip is written loudly to the
 * system log so billing can never be off without it being visible.
 */
class CronApiController extends Controller
{
    public const SETTING_ENABLED = 'billing_enabled';

    public function status(): JsonResponse
    {
        $lastRun = Cache::get('billing.dispatch.last_run');
        $lastCron = CronRun::query()->orderByDesc('ran_at')->first();

        return response()->json([
            'success' => true,
            'isRunning' => self::isEnabled(),
            'killSwitch' => (bool) config('billing.kill_switch'),
            'schedule' => 'every 5 minutes (with catch-up)',
            'lastDispatchAt' => $lastRun?->toIso8601String(),
            'lastCronRun' => $lastCron ? [
                'command' => $lastCron->command,
                'status' => $lastCron->status,
                'ran_at' => $lastCron->ran_at?->toIso8601String(),
                'runtime_ms' => $lastCron->runtime_ms,
            ] : null,
            'message' => self::isEnabled()
                ? 'Recurring billing is enabled.'
                : 'Recurring billing is DISABLED.',
        ]);
    }

    public function start(): JsonResponse
    {
        AppSetting::put(self::SETTING_ENABLED, '1');
        SystemLog::warning('cron', 'recurring billing ENABLED via API');

        return response()->json(['success' => true, 'message' => 'Recurring billing enabled.']);
    }

    public function stop(): JsonResponse
    {
        AppSetting::put(self::SETTING_ENABLED, '0');
        SystemLog::warning('cron', 'recurring billing DISABLED via API');

        return response()->json(['success' => true, 'message' => 'Recurring billing disabled.']);
    }

    /** v1 parity — the schedule is declarative in v2, so init just reports it. */
    public function init(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Schedule is declarative (routes/console.php) and always active on the scheduler service.',
            'schedule' => 'every 5 minutes (with catch-up)',
        ]);
    }

    /** Run the dispatcher right now. */
    public function trigger(): JsonResponse
    {
        SystemLog::info('cron', 'billing cycle triggered manually via API');

        Artisan::call('mills:dispatch-due');

        return response()->json([
            'success' => true,
            'message' => 'Billing cycle executed manually',
            'output' => trim(Artisan::output()),
        ]);
    }

    /** Billing runs unless the durable switch or the env kill switch says otherwise. */
    public static function isEnabled(): bool
    {
        if (config('billing.kill_switch')) {
            return false;
        }

        return AppSetting::get(self::SETTING_ENABLED) !== '0';
    }
}
