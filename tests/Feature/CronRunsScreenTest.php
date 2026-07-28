<?php

namespace Tests\Feature;

use App\Filament\Resources\CronRuns\CronRunResource;
use App\Models\CronRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scheduled-runs screen shows the runs.
 *
 * It shipped with `columns([])` — the scaffold nobody filled in — so the page rendered rows of
 * pure whitespace: every run was in the table, and not one of them was readable. It is the
 * screen you open to answer "did billing run", which makes an unreadable version worse than
 * no version at all.
 */
class CronRunsScreenTest extends TestCase
{
    use RefreshDatabase;

    private function cronRun(array $attributes = []): CronRun
    {
        return CronRun::query()->create(array_merge([
            'command' => 'mills:dispatch-due',
            'status' => 'completed',
            'runtime_ms' => 347,
            'output' => null,
            'ran_at' => now(),
        ], $attributes));
    }

    public function test_the_screen_shows_the_command_and_the_outcome(): void
    {
        $this->cronRun();
        $this->cronRun(['command' => 'mills:reconcile-payments', 'status' => 'failed', 'runtime_ms' => 8452]);

        $this->actingAs(User::factory()->create());

        $html = $this->get(CronRunResource::getUrl('index'))->assertOk()->getContent();

        $this->assertStringContainsString('mills:dispatch-due', $html);
        $this->assertStringContainsString('mills:reconcile-payments', $html);
        $this->assertStringContainsString(__('cron.status_completed'), $html);
        $this->assertStringContainsString(__('cron.status_failed'), $html);
        // Runtime in the unit a human reads — "8452" hides that this run took 8 seconds.
        $this->assertStringContainsString('8.5 s', $html);
        $this->assertStringContainsString('347 ms', $html);
    }

    public function test_the_log_cannot_be_created_edited_or_deleted(): void
    {
        $record = $this->cronRun();

        // These rows are the record of what the scheduler DID. Inventing a run that never
        // happened, or editing one that did, makes the log worthless as evidence.
        $this->assertFalse(CronRunResource::canCreate());
        $this->assertFalse(CronRunResource::canEdit($record));
        $this->assertFalse(CronRunResource::canDelete($record));

        $pages = array_keys(CronRunResource::getPages());
        $this->assertSame(['index', 'view'], $pages);
    }

    public function test_an_empty_log_says_the_scheduler_is_not_running(): void
    {
        $this->actingAs(User::factory()->create());

        // An empty table is not "nothing to see" — it means nobody is being charged.
        $this->get(CronRunResource::getUrl('index'))
            ->assertOk()
            ->assertSee(__('cron.empty_heading'));
    }
}
