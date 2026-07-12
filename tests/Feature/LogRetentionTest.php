<?php

namespace Tests\Feature;

use App\Models\CronRun;
use App\Models\SystemLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the logging contract: entries are written (with secrets masked) and the
 * retention window is enforced — anything older than the configured number of
 * days (default 60) is deleted by logs:prune, and nothing newer is touched.
 */
class LogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_writes_a_log_entry_and_masks_sensitive_context(): void
    {
        SystemLog::info('billing', 'charged subscription', [
            'amount' => 153.90,
            'buyer_key' => 'super-secret-key',
        ], ['subscription_id' => 7]);

        $log = SystemLog::query()->firstOrFail();

        $this->assertSame('info', $log->level);
        $this->assertSame('billing', $log->category);
        $this->assertSame('charged subscription', $log->message);
        $this->assertSame(7, (int) $log->subscription_id);
        $this->assertSame(153.90, $log->context['amount']);
        $this->assertSame('[REDACTED]', $log->context['buyer_key']);
    }

    public function test_prune_deletes_entries_older_than_the_retention_window_and_keeps_recent_ones(): void
    {
        config(['mills.logging.retention_days' => 60]);

        SystemLog::query()->create([
            'level' => 'info', 'category' => 'system', 'message' => 'old',
            'created_at' => now()->subDays(61),
        ]);
        SystemLog::query()->create([
            'level' => 'info', 'category' => 'system', 'message' => 'recent',
            'created_at' => now()->subDays(59),
        ]);

        CronRun::query()->create([
            'command' => 'old-run', 'status' => 'completed', 'ran_at' => now()->subDays(61),
        ]);
        CronRun::query()->create([
            'command' => 'recent-run', 'status' => 'completed', 'ran_at' => now()->subDays(10),
        ]);

        $this->artisan('logs:prune')->assertSuccessful();

        // The 61-day-old rows are gone; the ones inside the window survive.
        $this->assertFalse(SystemLog::query()->where('message', 'old')->exists());
        $this->assertTrue(SystemLog::query()->where('message', 'recent')->exists());

        $this->assertFalse(CronRun::query()->where('command', 'old-run')->exists());
        $this->assertTrue(CronRun::query()->where('command', 'recent-run')->exists());
    }
}
