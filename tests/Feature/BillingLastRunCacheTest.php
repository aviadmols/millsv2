<?php

namespace Tests\Feature;

use App\Filament\Widgets\SystemHealth;
use App\Http\Controllers\Api\CronApiController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The "when did billing last run" timestamp survives a round-trip through the cache.
 *
 * It did not, and it took the whole admin dashboard down. mills:dispatch-due cached a Carbon
 * OBJECT; the scheduler container serialized it, the web container unserialized it as a
 * __PHP_Incomplete_Class, and the SystemHealth widget threw "call to a method on an incomplete
 * object" the instant it read it. Every admin saw "Error while loading page" — and, cruelly,
 * the one panel that broke is the one whose entire job is to tell you billing is running.
 *
 * The fix is to cache a string and parse it. These tests pin both halves: a normal round-trip,
 * and — the actual production failure — a poisoned value that must degrade to "never ran"
 * instead of throwing.
 */
class BillingLastRunCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dispatcher_caches_a_string_not_a_carbon_object(): void
    {
        // A Carbon object in the cache is the bug. A scalar cannot deserialize into an
        // incomplete class.
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        $raw = Cache::get('billing.dispatch.last_run');

        $this->assertIsString($raw);
        $this->assertNotNull(CronApiController::lastDispatchAt());
    }

    public function test_a_valid_timestamp_reads_back(): void
    {
        Cache::forever('billing.dispatch.last_run', now()->subMinutes(3)->toIso8601String());

        $lastRun = CronApiController::lastDispatchAt();

        $this->assertNotNull($lastRun);
        $this->assertEqualsWithDelta(3, $lastRun->diffInMinutes(now()), 1);
    }

    public function test_a_poisoned_cache_value_does_not_throw(): void
    {
        // Exactly what the scheduler left behind: an object that unserializes incomplete.
        // The reader must treat it as "never ran", not detonate.
        Cache::forever('billing.dispatch.last_run', $this->incompleteObject());

        $this->assertNull(CronApiController::lastDispatchAt());
    }

    public function test_the_dashboard_widget_renders_over_a_poisoned_value(): void
    {
        Cache::forever('billing.dispatch.last_run', $this->incompleteObject());

        $this->actingAs(User::factory()->create());

        // The regression itself: the widget must render, not 500 the whole page.
        Livewire::test(SystemHealth::class)
            ->assertOk()
            ->assertSee(__('dashboard.health_billing'));
    }

    public function test_a_legacy_carbon_value_is_still_read_not_dropped(): void
    {
        // If a real Carbon ever does come back intact, honour it rather than discarding it.
        Cache::forever('billing.dispatch.last_run', now()->subMinute());

        $this->assertNotNull(CronApiController::lastDispatchAt());
    }

    /** A __PHP_Incomplete_Class, the way an unloadable serialized object deserializes. */
    private function incompleteObject(): object
    {
        return unserialize('O:22:"Some\\Unloadable\\ClassX":1:{s:4:"date";s:19:"2026-07-15 10:00:00";}');
    }
}
