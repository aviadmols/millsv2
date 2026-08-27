<?php

namespace Tests\Feature;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Jobs\ImportLegacyCustomersJob;
use App\Models\Customer;
use App\Models\ShopifyConnection;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bringing the Cardcom-era list across, 900 emails at a time.
 *
 * Two properties carry the whole thing. It must import the person the email NAMES —
 * Shopify's search is fuzzy, and importing a stranger's subscription onto somebody
 * else's account is worse than importing nobody's. And it must be safe to run twice,
 * because a run over 900 rows will be interrupted at some point.
 */
class ImportLegacyCustomersCommandTest extends TestCase
{
    use RefreshDatabase;

    private const NOTE = '{"discount":0.9,"interval":1,"status":"account-active","dogs":[{"status":"active","quizData":{"allergy":[],"age":4,"weight":12,"activity":1,"body":1},"name":"רקסי","sex":0,"avatar":1,"caloriesPerDay":420,"variants":[{"id":39357390782621,"grams":90,"price":180}]}],"nextDelivery":"2026-09-18"}';

    protected function setUp(): void
    {
        parent::setUp();

        ShopifyConnection::query()->create([
            'shop_domain' => 'millsforpets.myshopify.com',
            'access_token' => 'shpat_test',
            'installed_at' => now(),
        ]);
    }

    private function listFile(string ...$emails): string
    {
        $path = tempnam(sys_get_temp_dir(), 'emails').'.txt';
        file_put_contents($path, implode("\n", $emails)."\n");

        return $path;
    }

    /** @param list<array<string, mixed>> $customers */
    private function fakeShopify(array $customers): void
    {
        $nodes = array_map(fn (array $c) => [
            'id' => 'gid://shopify/Customer/'.$c['id'],
            'email' => $c['email'],
            'phone' => $c['phone'] ?? '+972501112233',
            'firstName' => $c['first_name'] ?? 'Dana',
            'lastName' => 'Levi',
            'note' => $c['note'] ?? '',
            'defaultAddress' => null,
        ], $customers);

        Http::fake([
            '*/graphql.json' => function ($request) use ($nodes) {
                $query = (string) ($request['query'] ?? '');

                // The importer re-reads the chosen customer by id; the command searches.
                if (str_contains($query, 'customer(')) {
                    return Http::response(['data' => ['customer' => $nodes[0] ?? null]]);
                }

                return Http::response(['data' => ['customers' => ['nodes' => $nodes]]]);
            },
        ]);
    }

    public function test_a_dry_run_reports_what_it_would_do_and_writes_nothing(): void
    {
        $this->fakeShopify([['id' => '900111', 'email' => 'dana@example.com', 'note' => self::NOTE]]);

        $this->artisan('mills:import-legacy-customers', [
            'file' => $this->listFile('dana@example.com'),
            '--dry-run' => true,
        ])->assertExitCode(0);

        // A list is checked against reality BEFORE it writes 900 rows.
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_an_imported_customer_arrives_behind_the_card_update_wall(): void
    {
        // The whole point: we hold no PayMe token for anyone on this list, so nothing may
        // be charged until they enter a card.
        $this->fakeShopify([['id' => '900111', 'email' => 'dana@example.com', 'note' => self::NOTE]]);

        $this->artisan('mills:import-legacy-customers', [
            'file' => $this->listFile('dana@example.com'),
        ])->assertExitCode(0);

        $subscription = Subscription::query()->firstOrFail();
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->payment_state);
        $this->assertSame('dana@example.com', Customer::query()->firstOrFail()->email);
    }

    public function test_a_fuzzy_search_hit_that_is_not_the_email_asked_for_is_refused(): void
    {
        /*
         * Shopify's `email:` search is a search, not a lookup — it will hand back
         * neighbours. Importing THEIR subscription onto this address would attach a
         * stranger's plan to somebody's account, and nobody would ever notice.
         */
        $this->fakeShopify([['id' => '900222', 'email' => 'someone.else@example.com', 'note' => self::NOTE]]);

        $this->artisan('mills:import-legacy-customers', [
            'file' => $this->listFile('dana@example.com'),
        ])->assertExitCode(0);

        $this->assertSame(0, Customer::query()->count());
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        // A walk over 900 emails gets interrupted. Starting again must be free.
        $this->fakeShopify([['id' => '900111', 'email' => 'dana@example.com', 'note' => self::NOTE]]);
        $file = $this->listFile('dana@example.com');

        $this->artisan('mills:import-legacy-customers', ['file' => $file])->assertExitCode(0);
        $this->artisan('mills:import-legacy-customers', ['file' => $file])->assertExitCode(0);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_the_limit_stops_early_so_a_few_can_be_tried_first(): void
    {
        $this->fakeShopify([
            ['id' => '900111', 'email' => 'dana@example.com', 'note' => self::NOTE],
        ]);

        $this->artisan('mills:import-legacy-customers', [
            'file' => $this->listFile('dana@example.com', 'second@example.com', 'third@example.com'),
            '--limit' => 1,
            '--dry-run' => true,
        ])->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_duplicates_and_junk_lines_in_the_list_are_ignored(): void
    {
        $this->fakeShopify([['id' => '900111', 'email' => 'dana@example.com', 'note' => self::NOTE]]);

        $this->artisan('mills:import-legacy-customers', [
            'file' => $this->listFile('dana@example.com', '', 'DANA@example.com', 'not-an-email'),
            '--dry-run' => true,
        ])->assertExitCode(0);

        // One address, however many times and in whatever case it was written down.
        Http::assertSentCount(1);
    }

    public function test_the_admin_action_dispatches_small_chunks_never_one_giant_job(): void
    {
        /*
         * The failure mode this pins: one job carrying the whole list runs far past the
         * queue's 90-second release window, gets picked up again mid-run, fights itself,
         * and is stamped failed on every attempt — which is exactly how the first run of
         * the 1,172 list died, split in two or not. A chunk must NEVER outlive the window.
         */
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $emails = implode("\n", array_map(fn (int $i) => "person{$i}@example.com", range(1, 30)));

        Livewire::test(ListCustomers::class)
            ->mountAction('bulkImport')
            ->setActionData(['emails' => $emails, 'dry_run' => false, 'limit' => 0])
            ->callMountedAction();

        Queue::assertPushed(ImportLegacyCustomersJob::class, 3);   // 12 + 12 + 6
        Queue::assertPushed(ImportLegacyCustomersJob::class, fn ($job) => count($job->emails) <= ImportLegacyCustomersJob::CHUNK_SIZE);
    }

    // --- people who are already here -----------------------------------------

    public function test_an_email_already_here_with_a_subscription_is_left_untouched(): void
    {
        // The guard that lets the whole list be re-run: importing on top of an existing
        // subscription would double everything the person is billed for.
        $customer = Customer::query()->create(['email' => 'dana@example.com', 'shopify_customer_id' => '900111']);
        $subscription = new Subscription;
        $subscription->fill(['customer_id' => $customer->id, 'payment_state' => 'payme', 'frequency_months' => 1, 'next_charge_at' => now()->addDay()]);
        $subscription->forceFill(['status' => 'active'])->save();

        $this->fakeShopify([['id' => '900111', 'email' => 'dana@example.com', 'note' => self::NOTE]]);

        $this->artisan('mills:import-legacy-customers', ['file' => $this->listFile('dana@example.com')])
            ->assertExitCode(0);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Subscription::query()->count());
    }

    public function test_an_email_already_here_without_a_shopify_id_is_linked_not_duplicated(): void
    {
        /*
         * The common case in a 1,172-address list: somebody who once logged into the
         * personal area exists here by email alone. Both email and shopify id are UNIQUE
         * columns, so "just insert" does not duplicate them — it blows up, and the row
         * counts as a failure. The same person must be recognised and linked.
         */
        Customer::query()->create(['email' => 'dana@example.com']);

        $this->fakeShopify([['id' => '900111', 'email' => 'dana@example.com', 'note' => self::NOTE]]);

        $this->artisan('mills:import-legacy-customers', ['file' => $this->listFile('dana@example.com')])
            ->assertExitCode(0);

        $customer = Customer::query()->sole();
        $this->assertSame('900111', $customer->shopify_customer_id);   // linked, not doubled
        $this->assertSame(1, Subscription::query()->count());          // and the subscription arrived
    }
}
