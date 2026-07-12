<?php

namespace App\Console\Commands;

use App\Models\CronRun;
use App\Models\SystemLog;
use Illuminate\Console\Command;

/**
 * Enforces log retention: deletes system_logs (and cron_runs) older than
 * config('mills.logging.retention_days') (default 60 days). Scheduled daily in
 * routes/console.php. Keeps the log tables bounded so they never grow unbounded.
 */
class PruneLogsCommand extends Command
{
    protected $signature = 'logs:prune {--days= : Override the retention window in days}';

    protected $description = 'Delete system logs and cron-run rows older than the retention window (default 60 days).';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('mills.logging.retention_days', 60));
        $days = max(1, $days);
        $cutoff = now()->subDays($days);

        $logs = SystemLog::query()->where('created_at', '<', $cutoff)->delete();
        $crons = CronRun::query()->where('ran_at', '<', $cutoff)->delete();

        $message = "Pruned {$logs} system log(s) and {$crons} cron run(s) older than {$days} days.";
        $this->info($message);

        SystemLog::info('system', 'log retention prune', [
            'retention_days' => $days,
            'system_logs_deleted' => $logs,
            'cron_runs_deleted' => $crons,
        ]);

        return self::SUCCESS;
    }
}
