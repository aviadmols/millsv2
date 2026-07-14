<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyCustomerService;
use App\Modules\MillsSubscriptions\Support\CustomerMapper;
use App\Modules\MillsSubscriptions\Support\LegacyNoteParser;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\ShopifyId;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bring a customer over from Shopify — including the subscription hiding in their note.
 *
 * These are the iCount customers: the ones who never had a PayMe card. v1 kept their whole
 * subscription as JSON in the Shopify customer note; v2's one-time import took only the PayMe
 * half of the population and skipped them, so they do not exist here at all. They are also,
 * precisely, the people who need to update a card.
 *
 * Every subscription created here is born behind the card-update wall
 * (`payment_state = needs_card_update`), which already means: billing skips it, the personal
 * area shows the "update your card" banner, and the first successful card update lifts it.
 */
class LegacyCustomerImporter
{
    public const STATUS_IMPORTED = 'imported';

    public const STATUS_ALREADY_HAS_SUBSCRIPTION = 'already_has_subscription';

    public const STATUS_NO_NOTE = 'no_note';

    public const STATUS_NOT_FOUND = 'not_found';

    public function __construct(private readonly ShopifyCustomerService $customers) {}

    /**
     * What WILL be created, without creating any of it. Reads only.
     *
     * @return array<string, mixed>
     */
    public function preview(string $idOrGid): array
    {
        $payload = $this->customers->find($idOrGid);

        if ($payload === []) {
            return ['status' => self::STATUS_NOT_FOUND];
        }

        $note = LegacyNoteParser::parseActiveNote((string) ($payload['note'] ?? ''));
        $existing = Customer::query()->where('shopify_customer_id', (string) $payload['id'])->first();

        $status = match (true) {
            $existing !== null && $existing->subscriptions()->exists() => self::STATUS_ALREADY_HAS_SUBSCRIPTION,
            $note === null => self::STATUS_NO_NOTE,
            default => self::STATUS_IMPORTED,
        };

        return [
            'status' => $status,
            'customer' => $payload,
            'note' => $note,
            // What will actually be stored — the rolled-forward date, not the note's stale one.
            'next_charge_at' => $note !== null
                ? $this->nextChargeAt($note['next_charge_at'], $note['frequency_months'])?->toDateString()
                : null,
        ];
    }

    /**
     * @return array{status: string, customer_id: ?int, subscription_id: ?int, dogs: int}
     */
    public function import(string $idOrGid, ?int $adminId = null): array
    {
        $payload = $this->customers->find($idOrGid);

        if ($payload === []) {
            return $this->result(self::STATUS_NOT_FOUND);
        }

        $gid = ShopifyId::gid((string) $payload['id'], 'Customer');

        // The customer is added whatever the note says. "Add customer from Shopify" is the
        // action; importing their old subscription is the conditional half of it.
        $customer = CustomerMapper::upsert($payload, ['legacy_shopify_gid' => $gid]);

        if ($customer === null) {
            return $this->result(self::STATUS_NOT_FOUND);
        }

        // Idempotency, and a guard against colliding with the v1 PayMe import: a customer who
        // already has a subscription here is already known, and a second one would double
        // everything they are billed for.
        if ($customer->subscriptions()->exists()) {
            return $this->result(self::STATUS_ALREADY_HAS_SUBSCRIPTION, $customer->id);
        }

        $note = LegacyNoteParser::parseActiveNote((string) ($payload['note'] ?? ''));

        if ($note === null) {
            SystemLog::info('admin', 'customer added from Shopify — no active legacy subscription in the note', [
                'shopify_customer_id' => $payload['id'],
                'admin_id' => $adminId,
            ], ['customer_id' => $customer->id]);

            return $this->result(self::STATUS_NO_NOTE, $customer->id);
        }

        [$subscription, $dogs] = DB::transaction(function () use ($customer, $note, $gid, $adminId) {
            $subscription = $this->createSubscription($customer, $note, $gid, $adminId);
            $dogs = $this->createDogs($customer, $subscription, $note, $gid);

            return [$subscription, $dogs];
        });

        SystemLog::info('admin', 'a legacy subscription was imported from the Shopify customer note', [
            'admin_id' => $adminId,
            'dogs' => $dogs,
            'frequency_months' => $note['frequency_months'],
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        Timeline::record(
            Timeline::KIND_SUBSCRIPTION_CREATED,
            ['source' => 'shopify_customer_note', 'dogs' => $dogs],
            $subscription->id,
            $customer->id,
            $adminId !== null ? Timeline::admin($adminId) : Timeline::ACTOR_SYSTEM,
        );

        // Outside the transaction, and best-effort: building the upcoming order is a Shopify
        // round-trip, and holding row locks open across someone else's HTTP call is how a
        // slow Shopify becomes a locked database.
        $this->buildUpcomingOrder($subscription);

        return $this->result(self::STATUS_IMPORTED, $customer->id, $subscription->id, $dogs);
    }

    /** @param array<string, mixed> $note */
    private function createSubscription(Customer $customer, array $note, string $gid, ?int $adminId): Subscription
    {
        $key = $gid.'#legacy-note';

        $subscription = Subscription::query()->firstOrNew(['legacy_shopify_gid' => $key]);

        $attributes = [
            'customer_id' => $customer->id,
            // THE WALL. Billing never touches this subscription until a card is entered.
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'frequency_months' => $note['frequency_months'],
            'next_charge_at' => $this->nextChargeAt($note['next_charge_at'], $note['frequency_months']),
            'meta' => [
                'imported_from' => 'shopify_customer_note',
                'imported_by' => $adminId,
                'imported_at' => now()->toIso8601String(),
                'note' => $note,
            ],
        ];

        // A note that carries no usable discount leaves the column default (10%) standing —
        // it does NOT get written as null, which would bill the customer full price.
        if ($note['discount_percent'] !== null) {
            $attributes['discount_percent'] = $note['discount_percent'];
        }

        $subscription->fill($attributes);

        // `status` is mass-assignment-guarded (it moves only through the state machine), so
        // the initial value has to be forced. Same idiom as ImportFromV1Command.
        $subscription->forceFill([
            'status' => SubscriptionStatus::ACTIVE->value,
            'legacy_shopify_gid' => $key,
        ])->save();

        return $subscription;
    }

    /**
     * When the subscription is next due.
     *
     * The note's `nextDelivery` is almost always in the PAST — these accounts have been idle
     * for months. Stored as-is, the subscription is overdue the moment it exists, and the
     * instant a card update lifts the wall the dispatcher charges it within five minutes: a
     * customer who did nothing but update their card is billed on the spot, possibly for an
     * order that was never built. So a stale date is rolled forward by whole cycles.
     *
     * A missing or unparseable date becomes NULL, not today — the dispatcher requires a date,
     * so null is a visible "—" on the screen rather than an immediate charge.
     */
    private function nextChargeAt(?string $noteDate, int $frequencyMonths): ?Carbon
    {
        if ($noteDate === null) {
            return null;
        }

        try {
            $date = Carbon::parse($noteDate)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        $months = max(1, $frequencyMonths);
        $guard = 0;

        while ($date->isPast() && $guard < 120) {
            $date = $date->addMonths($months);
            $guard++;
        }

        return $date->isPast() ? null : $date;
    }

    /** @param array<string, mixed> $note */
    private function createDogs(Customer $customer, Subscription $subscription, array $note, string $gid): int
    {
        $count = 0;

        foreach ($note['dogs'] as $index => $dog) {
            // The note carries no dog ids, so the ordinal is the only stable handle there is.
            $key = $gid.'#dog-'.$index;

            /*
             * DogObserver rebuilds the upcoming order on every save — a synchronous Shopify
             * call per dog. Left alone, importing a 3-dog customer makes 3 HTTP calls from
             * inside this transaction, holding row locks for as long as Shopify feels like
             * taking. One deliberate rebuild happens after the commit instead.
             */
            Dog::withoutEvents(function () use ($key, $customer, $subscription, $dog, $note) {
                Dog::query()->updateOrCreate(['legacy_shopify_gid' => $key], [
                    'customer_id' => $customer->id,
                    'subscription_id' => $subscription->id,
                    'name' => $dog['name'],
                    'sex' => $dog['sex'],
                    'avatar' => $dog['avatar'],
                    'age' => $dog['age'],
                    'weight' => $dog['weight'],
                    'activity' => $dog['activity'],
                    'body' => $dog['body'],
                    'allergies' => $dog['allergies'] !== '' ? $dog['allergies'] : null,
                    'calories_per_day' => $dog['calories_per_day'],
                    'double_food' => $note['double_food'],
                    'status' => 'active',
                    'selected_variants' => $dog['variants'],
                ]);
            });

            $count++;
        }

        return $count;
    }

    /** The screen must show what will ship — but a Shopify outage must not lose the import. */
    private function buildUpcomingOrder(Subscription $subscription): void
    {
        try {
            app(DraftOrderService::class)->ensure($subscription->fresh());
        } catch (Throwable $e) {
            SystemLog::warning('shopify', 'the imported subscription has no upcoming order yet', [
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        }
    }

    /** @return array{status: string, customer_id: ?int, subscription_id: ?int, dogs: int} */
    private function result(string $status, ?int $customerId = null, ?int $subscriptionId = null, int $dogs = 0): array
    {
        return [
            'status' => $status,
            'customer_id' => $customerId,
            'subscription_id' => $subscriptionId,
            'dogs' => $dogs,
        ];
    }
}
