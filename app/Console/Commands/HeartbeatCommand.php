<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Scheduler liveness beacon (ARCHITECTURE.md §5). The observability page reads
 * `scheduler.last_heartbeat_at` to prove the dedicated scheduler service is alive
 * — the v1 failure this design prevents.
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'mills:heartbeat';

    protected $description = 'Record that the scheduler is alive.';

    public function handle(): int
    {
        Cache::forever('scheduler.last_heartbeat_at', now()->toIso8601String());

        return self::SUCCESS;
    }
}
