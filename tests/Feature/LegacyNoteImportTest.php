<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use App\Modules\MillsSubscriptions\Services\LegacyCustomerImporter;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use App\Modules\MillsSubscriptions\Support\CustomerMapper;
use App\Modules\MillsSubscriptions\Support\LegacyNoteParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The iCount customers — the ones who never had a PayMe card.
 *
 * v1 kept their entire subscription as JSON in the Shopify customer note. v2's one-time
 * import took only the PayMe half of the population and skipped them, so in v2 they do not
 * exist at all — which is awkward, because they are precisely the people who need to update a
 * card. This is how they come back, and what must not go wrong when they do.
 */
class LegacyNoteImportTest extends TestCase
{
    use RefreshDatabase;

    /** The real thing, from v1's own docblock. */
    private const NOTE = <<<'JSON'
    {"discount":0.9,"interval":1,"status":"account-active","dogs":[{"status":"active",
     "quizData":{"allergy":["עוף"],"age":8,"weight":3,"activity":0,"body":1},"name":"כלב 1",
     "sex":0,"avatar":1,"caloriesPerDay":191,"variants":[{"id":39357390782621,
     "handle":"h","name":"n","grams":1530,"price":171}]}],"nextDelivery":"2026-06-18"}
    JSON;

    /** Stand in for Shopify: the importer only ever asks for one customer. */
    private function fakeShopify(string $note, string $id = '900123'): void
    {
        $this->app->instance(ShopifyCustomerService::class, new class($note, $id) extends ShopifyCustomerService
        {
            public function __construct(private string $note, private string $id) {}

            public function search(string $term, int $limit = 20): array
            {
                return [$this->find($this->id)];
            }

            public function find(string $idOrGid): array
            {
                return [
                    'id' => $this->id,
                    'email' => 'icount@example.com',
                    'phone' => '0521112222',
                    'first_name' => 'Anat',
                    'last_name' => 'Levi',
                    'note' => $this->note,
                    'default_address' => ['address1' => 'Herzl 1', 'city' => 'Tel Aviv', 'zip' => '600000'],
                ];
            }
        });
    }

    // --- the parser ----------------------------------------------------------

    public function test_the_real_note_parses(): void
    {
        $note = LegacyNoteParser::parseActiveNote(self::NOTE);

        $this->assertNotNull($note);
        $this->assertSame(1, $note['frequency_months']);
        $this->assertSame('2026-06-18', $note['next_charge_at']);
        $this->assertSame(10.0, $note['discount_percent']);
        $this->assertCount(1, $note['dogs']);

        $dog = $note['dogs'][0];
        $this->assertSame('כלב 1', $dog['name']);
        $this->assertSame(8, $dog['age']);
        $this->assertSame(3, $dog['weight']);
        $this->assertSame('עוף', $dog['allergies']);
        $this->assertSame(['gid://shopify/ProductVariant/39357390782621'], $dog['variants']);
    }

    public function test_both_spellings_of_active_are_accepted(): void
    {
        // iCount wrote "account-active"; later exports write plain "active". Rejecting the
        // second would silently drop real, paying customers.
        $base = '{"interval":1,"dogs":[{"variants":[{"id":1}]}],"status":"%s"}';

        $this->assertNotNull(LegacyNoteParser::parseActiveNote(sprintf($base, 'account-active')));
        $this->assertNotNull(LegacyNoteParser::parseActiveNote(sprintf($base, 'active')));
        $this->assertNull(LegacyNoteParser::parseActiveNote(sprintf($base, 'account-disabled')));
    }

    public function test_a_dog_with_no_variants_is_dropped(): void
    {
        // Nothing to ship. Importing it would create a subscription with an empty order.
        $note = '{"status":"active","dogs":[{"name":"Empty","variants":[]},{"name":"Real","variants":[{"id":7}]}]}';

        $parsed = LegacyNoteParser::parseActiveNote($note);

        $this->assertCount(1, $parsed['dogs']);
        $this->assertSame('Real', $parsed['dogs'][0]['name']);
    }

    public function test_junk_notes_yield_nothing(): void
    {
        $this->assertNull(LegacyNoteParser::parseActiveNote(''));
        $this->assertNull(LegacyNoteParser::parseActiveNote('a note the shop owner typed'));
        $this->assertNull(LegacyNoteParser::parseActiveNote('{"status":"active","dogs":[]}'));
    }

    public function test_the_discount_is_a_multiplier_not_a_percentage(): void
    {
        // "discount": 0.85 means PAY 85% — a 15% discount. Ignore it and this customer
        // silently gets the 10% default: a 5% overcharge on every order, forever.
        $note = '{"status":"active","discount":0.85,"dogs":[{"variants":[{"id":1}]}]}';

        $this->assertSame(15.0, LegacyNoteParser::parseActiveNote($note)['discount_percent']);

        // Anything that is not a multiplier is not guessed at — the column default stands.
        $bogus = '{"status":"active","discount":90,"dogs":[{"variants":[{"id":1}]}]}';
        $this->assertNull(LegacyNoteParser::parseActiveNote($bogus)['discount_percent']);
    }

    // --- the import ----------------------------------------------------------

    public function test_importing_creates_the_customer_the_subscription_and_the_dogs(): void
    {
        $this->fakeShopify(self::NOTE);

        $result = app(LegacyCustomerImporter::class)->import('900123', adminId: 3);

        $this->assertSame(LegacyCustomerImporter::STATUS_IMPORTED, $result['status']);
        $this->assertSame(1, $result['dogs']);

        $customer = Customer::query()->where('shopify_customer_id', '900123')->firstOrFail();
        $this->assertSame('Anat', $customer->first_name);
        $this->assertSame('Herzl 1', $customer->address1);

        $subscription = Subscription::query()->where('customer_id', $customer->id)->firstOrFail();
        // The wall: this subscription exists, but nothing will charge it.
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->payment_state);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->status);
        $this->assertSame(10.0, (float) $subscription->discount_percent);

        $dog = Dog::query()->where('subscription_id', $subscription->id)->firstOrFail();
        $this->assertSame('כלב 1', $dog->name);
        $this->assertSame(['gid://shopify/ProductVariant/39357390782621'], $dog->selected_variants);
    }

    public function test_importing_the_same_customer_twice_changes_nothing(): void
    {
        $this->fakeShopify(self::NOTE);

        $importer = app(LegacyCustomerImporter::class);
        $importer->import('900123');

        $result = $importer->import('900123');

        // A second subscription would double everything the customer is billed for.
        $this->assertSame(LegacyCustomerImporter::STATUS_ALREADY_HAS_SUBSCRIPTION, $result['status']);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(1, Dog::query()->count());
    }

    public function test_a_customer_with_no_note_is_still_added(): void
    {
        $this->fakeShopify('');

        $result = app(LegacyCustomerImporter::class)->import('900123');

        // "Add a customer" is the action; importing their old subscription is the conditional
        // half of it. Refusing the whole thing would be refusing to add a customer.
        $this->assertSame(LegacyCustomerImporter::STATUS_NO_NOTE, $result['status']);
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(0, Subscription::query()->count());
    }

    // --- what must NOT happen ------------------------------------------------

    public function test_an_imported_subscription_is_never_charged(): void
    {
        $this->fakeShopify(self::NOTE);

        app(LegacyCustomerImporter::class)->import('900123');

        $subscription = Subscription::query()->firstOrFail();
        $subscription->forceFill(['next_charge_at' => now()->subDay()])->save();

        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        // Billing only ever touches `payme`. An imported customer has no card, so charging
        // them is impossible — and must stay impossible.
        $this->assertSame(0, $subscription->fresh()->attempt_count);
    }

    public function test_a_stale_delivery_date_does_not_become_an_instant_charge(): void
    {
        // These accounts have been idle for months, so nextDelivery is deep in the past.
        $stale = str_replace('2026-06-18', now()->subMonths(8)->toDateString(), self::NOTE);
        $this->fakeShopify($stale);

        app(LegacyCustomerImporter::class)->import('900123');

        $subscription = Subscription::query()->firstOrFail();

        /*
         * THE TRAP: stored as-is, the subscription is overdue the moment it exists. The
         * instant a card update lifts the wall, the dispatcher charges it within five
         * minutes — a customer who did nothing but update their card is billed on the spot.
         */
        $this->assertTrue($subscription->next_charge_at->isFuture());

        $customer = $subscription->customer;
        app(CardUpdateService::class)->liftCardUpdateWall($customer);

        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);

        $this->artisan('mills:dispatch-due')->assertExitCode(0);
        $this->assertSame(0, $subscription->fresh()->attempt_count);
    }

    public function test_the_importer_and_the_webhook_map_a_customer_identically(): void
    {
        // Two hand-written copies of this mapping is one field waiting to be forgotten — the
        // kind of drift where a customer added by staff quietly has no phone number.
        $payload = [
            'id' => '5551',
            'email' => 'x@example.com',
            'first_name' => 'Ron',
            'default_address' => ['address1' => 'Dizengoff 2', 'city' => 'TLV', 'phone' => '0500000000'],
        ];

        $attributes = CustomerMapper::attributes($payload);

        $this->assertSame('x@example.com', $attributes['email']);
        $this->assertSame('Ron', $attributes['first_name']);
        $this->assertSame('Dizengoff 2', $attributes['address1']);
        $this->assertSame('0500000000', $attributes['phone']);   // falls back to the address
    }
}
