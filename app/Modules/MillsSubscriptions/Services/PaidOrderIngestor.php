<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\QuizDog;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Support\Carbon;

/**
 * Turns a paid Shopify order into a subscription (ARCHITECTURE.md §1b, the wiring
 * the job's docblock deferred to "Phase 4/5").
 *
 * The storefront sells a subscription by checking out variants of the hidden
 * pricing product (`subs-test`, "מנוי מתחדש") — each variant is a flavor+size at
 * the subscription price. That line item is therefore the signup signal; a plain
 * shop order (treats, one-off food) must never become a subscription, which is
 * why ingest() is a no-op for orders that carry no subscription line.
 *
 * The new theme also attaches what the shopper chose: `_subscription` / `תדירות`
 * line properties, a `_quiz_data` property holding the quiz summary (legacy-note
 * shape), and a `quiz_dog_id` order note attribute pointing at the QuizDog saved
 * before checkout. Older orders carry only the product line — they still count.
 *
 * The subscription starts PENDING + NEEDS_CARD_UPDATE: the shopper paid THIS
 * order through Shopify checkout, so we hold no PayMe token for the next cycle.
 * Their first card update in the personal area flips them to payme, exactly like
 * an imported legacy-note customer.
 */
class PaidOrderIngestor
{
    public function __construct(private readonly CheckoutCardCapture $checkoutCard) {}

    /** Create the subscription a paid order signed up for. Null when it is a plain sale. */
    public function ingest(array $order): ?Subscription
    {
        $lines = $this->subscriptionLines($order);
        if ($lines === []) {
            return null;
        }

        $orderId = (string) ($order['id'] ?? '');
        if ($orderId === '') {
            return null;
        }

        $customer = $this->resolveCustomer($order);
        if ($customer === null) {
            SystemLog::warning('webhook', 'subscription order has no customer — nothing to attach it to', [
                'order_id' => $orderId, 'order_name' => (string) ($order['name'] ?? ''),
            ]);

            return null;
        }

        $quiz = $this->quizData($lines);
        $frequency = $this->frequencyMonths($quiz, $lines);
        $amount = $this->recurringAmount($lines);

        $subscription = Subscription::query()
            ->where('customer_id', $customer->id)
            ->where('original_order_id', $orderId)
            ->first();

        if ($subscription !== null) {
            // Redelivery or the sweep passing again. Nothing to create — but if the wall
            // is still up (checkout capture failed last time, or the code shipped after
            // this order), each pass is another chance to pull the card from the payment.
            $this->checkoutCard->attempt($subscription);

            return $subscription;
        }

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'frequency_months' => $frequency,
            'original_order_id' => $orderId,
            'next_charge_at' => $this->orderDate($order)->addMonthsNoOverflow($frequency)->startOfDay(),
            'next_charge_amount' => $amount > 0 ? $amount : null,
            // The admin-set subscriber discount (Settings → billing) — stamped at signup,
            // so changing the setting later moves NEW signups without touching anyone's
            // existing deal. Falls back to the column default (10) when unset.
            'discount_percent' => (float) AppSetting::get('subscription_discount_percent', '10'),
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::PENDING->value])->save();

        $this->attachDog($subscription, $customer, $order, $quiz, $lines);

        SystemLog::info('webhook', 'subscription created from paid order', [
            'order_id' => $orderId,
            'order_name' => (string) ($order['name'] ?? ''),
            'amount' => $amount,
            'frequency_months' => $frequency,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        Timeline::record(Timeline::KIND_SUBSCRIPTION_CREATED, [
            'order_id' => $orderId,
            'order_name' => (string) ($order['name'] ?? ''),
            'amount' => $amount,
        ], $subscription->id, $customer->id, Timeline::ACTOR_WEBHOOK);

        // The shopper just typed their card into PayMe at this very checkout — pull the
        // reusable token from that payment instead of asking them to type it again.
        // Best-effort: on failure the wall stays and the sweep retries in 15 minutes.
        $this->checkoutCard->attempt($subscription);

        return $subscription;
    }

    /**
     * The order's subscription line items. A line counts when the theme flagged it
     * (`_subscription` property) or it sells the hidden pricing product — older
     * theme versions attach no properties at all.
     *
     * @return list<array<string, mixed>>
     */
    public function subscriptionLines(array $order): array
    {
        $productId = $this->subscriptionProductId();
        $lines = [];

        foreach ((array) ($order['line_items'] ?? []) as $line) {
            $line = (array) $line;

            if ($this->property($line, '_subscription') !== null
                || ($productId !== null && (string) ($line['product_id'] ?? '') === $productId)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    // === Internals ===

    /** The catalog's Shopify id for the hidden pricing product (null when unsynced). */
    private function subscriptionProductId(): ?string
    {
        $handle = (string) config('shopify.subscription_product_handle', 'subs-test');

        $id = Product::query()->where('handle', $handle)->value('shopify_product_id');

        return $id !== null ? (string) $id : null;
    }

    private function resolveCustomer(array $order): ?Customer
    {
        $shopifyId = (string) (data_get($order, 'customer.id') ?? '');
        if ($shopifyId === '') {
            return null;
        }

        $customer = Customer::query()->where('shopify_customer_id', $shopifyId)->first();
        if ($customer !== null) {
            return $customer;
        }

        $email = trim((string) (data_get($order, 'customer.email') ?? $order['email'] ?? ''));

        // A shopper who already exists locally under this email (imported, OTP
        // login) is the same person — claim the Shopify id rather than colliding
        // with the unique email column.
        if ($email !== '') {
            $existing = Customer::query()->where('email', $email)->first();
            if ($existing !== null) {
                if ($existing->shopify_customer_id === null) {
                    $existing->forceFill(['shopify_customer_id' => $shopifyId])->save();
                }

                return $existing;
            }
        }

        return Customer::query()->create([
            'shopify_customer_id' => $shopifyId,
            'email' => $email !== '' ? $email : null,
            'phone' => (string) (data_get($order, 'customer.phone')
                ?? data_get($order, 'customer.default_address.phone')
                ?? data_get($order, 'shipping_address.phone')
                ?? '') ?: null,
            'first_name' => (string) (data_get($order, 'customer.first_name') ?? '') ?: null,
            'last_name' => (string) (data_get($order, 'customer.last_name') ?? '') ?: null,
        ]);
    }

    /** The `_quiz_data` property the theme attaches (legacy-note shape), if any. */
    private function quizData(array $lines): array
    {
        foreach ($lines as $line) {
            $raw = $this->property($line, '_quiz_data');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        return [];
    }

    private function frequencyMonths(array $quiz, array $lines): int
    {
        $interval = (int) ($quiz['interval'] ?? 0);
        if ($interval >= 1) {
            return $interval === 2 ? 2 : 1;
        }

        foreach ($lines as $line) {
            $label = (string) ($this->property($line, 'תדירות') ?? '');
            if (str_contains($label, 'חודשיים') || str_contains($label, 'דו')) {
                return 2;
            }
        }

        return 1;
    }

    /** What the next cycle should cost: the subscription lines, already at subscription prices. */
    private function recurringAmount(array $lines): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $total += (float) ($line['price'] ?? 0) * max(1, (int) ($line['quantity'] ?? 1));
        }

        return round($total, 2);
    }

    /**
     * Give the subscription its dog: the QuizDog saved before checkout when the
     * order points at one, else a dog built from `_quiz_data`, else a bare dog
     * holding just the purchased variants — without one the next cycle has no
     * flavors to ship.
     */
    private function attachDog(Subscription $subscription, Customer $customer, array $order, array $quiz, array $lines): void
    {
        $variantIds = array_values(array_filter(array_map(
            static fn (array $line): string => (string) ($line['variant_id'] ?? ''),
            $lines,
        ), static fn (string $id): bool => $id !== ''));

        $quizDog = $this->quizDogFor($order);

        if ($quizDog?->linkedDog !== null) {
            $quizDog->linkedDog->forceFill([
                'subscription_id' => $subscription->id,
                'subscription_status' => 'active',
            ])->save();

            if ($quizDog->linkedDog->selected_variants === null && $variantIds !== []) {
                $quizDog->linkedDog->forceFill(['selected_variants' => $variantIds])->save();
            }

            return;
        }

        $noteDog = (array) (data_get($quiz, 'dogs.0') ?? []);
        $noteQuiz = (array) ($noteDog['quizData'] ?? []);

        $dog = Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => (string) ($noteDog['name'] ?? '') ?: null,
            'sex' => isset($noteDog['sex']) ? (int) $noteDog['sex'] : null,
            'age' => isset($noteQuiz['age']) ? (float) $noteQuiz['age'] : null,
            'weight' => isset($noteQuiz['weight']) ? (float) $noteQuiz['weight'] : null,
            'activity' => isset($noteQuiz['activity']) ? (int) $noteQuiz['activity'] : null,
            'body' => isset($noteQuiz['body']) ? (int) $noteQuiz['body'] : null,
            'allergies' => (string) ($noteQuiz['allergy'] ?? '') ?: null,
            'calories_per_day' => isset($noteDog['caloriesPerDay']) ? (int) $noteDog['caloriesPerDay'] : null,
            'avatar' => isset($noteDog['avatar']) ? (string) $noteDog['avatar'] : null,
            'subscription_status' => 'active',
            'selected_variants' => $variantIds !== [] ? $variantIds : null,
        ]);

        if ($quizDog !== null) {
            $quizDog->forceFill([
                'customer_id' => $customer->id,
                'linked_dog_id' => $dog->id,
                'linked_at' => now(),
            ])->save();
        }
    }

    private function quizDogFor(array $order): ?QuizDog
    {
        $publicId = null;

        foreach ((array) ($order['note_attributes'] ?? []) as $attribute) {
            if ((string) (data_get($attribute, 'name') ?? '') === 'quiz_dog_id') {
                $publicId = trim((string) (data_get($attribute, 'value') ?? ''));
            }
        }

        if ($publicId === null || $publicId === '') {
            return null;
        }

        return QuizDog::query()->where('public_id', $publicId)->first();
    }

    private function orderDate(array $order): Carbon
    {
        $raw = (string) ($order['processed_at'] ?? $order['created_at'] ?? '');

        try {
            return $raw !== '' ? Carbon::parse($raw) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    /** A line-item property by name (Shopify sends [{name, value}, ...]). */
    private function property(array $line, string $name): ?string
    {
        foreach ((array) ($line['properties'] ?? []) as $property) {
            if ((string) (data_get($property, 'name') ?? '') === $name) {
                return (string) (data_get($property, 'value') ?? '');
            }
        }

        return null;
    }
}
