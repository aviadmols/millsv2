<?php

namespace App\Http\Controllers\Storefront;

use App\Models\Dog;
use App\Models\QuizDog;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use App\Support\ShopifyId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Customer-driven dog writes (SYSTEM-MAP §3.3).
 *
 * The card-update wall applies only to BILLING-AFFECTING changes (flavor variant,
 * add-ons) — profile edits and removing a dog are never gated, exactly as in v1.
 */
class StorefrontDogController extends AbstractStorefrontController
{
    /** Profile fields a customer is allowed to edit. */
    private const EDITABLE = [
        'name', 'sex', 'age', 'weight', 'allergies', 'activity',
        'body', 'birth_date', 'avatar', 'double_food',
    ];

    public function update(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $dog = $this->findOwnedDog($customer, $id);

        // v1 nests the changes under `updates`; accept a flat body too.
        $updates = $request->input('updates');
        $updates = is_array($updates) ? $updates : $request->except(['updates']);
        $updates = array_intersect_key($updates, array_flip(self::EDITABLE));

        if ($updates === []) {
            return $this->fail('no_fields', 'לא נשלחו שדות לעדכון.', 422);
        }

        $dog->fill($updates)->save();

        SystemLog::info('storefront', 'dog profile updated', [
            'fields' => array_keys($updates),
        ], ['subscription_id' => $dog->subscription_id, 'customer_id' => $customer->id]);

        return $this->ok(['dog' => StorefrontPresenter::dog($dog->fresh())]);
    }

    /** Change the recurring flavor variant(s) — billing-affecting. */
    public function changeVariant(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $dog = $this->findOwnedDog($customer, $id);
        $this->guardDogBilling($dog);

        $variants = $this->idList($request, ['variantId', 'variantIds', 'id']);
        if ($variants === []) {
            return $this->fail('variant_id_required', 'נדרש מזהה וריאנט.', 422);
        }

        $dog->selected_variants = $variants;
        $dog->save();

        SystemLog::info('storefront', 'dog flavor variants changed', [
            'variants' => $variants,
        ], ['subscription_id' => $dog->subscription_id, 'customer_id' => $customer->id]);

        return $this->ok(['dog' => StorefrontPresenter::dog($dog->fresh())]);
    }

    public function addAddon(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $dog = $this->findOwnedDog($customer, $id);
        $this->guardDogBilling($dog);

        $variants = $this->idList($request, ['variantId', 'id', 'variantIds']);
        if ($variants === []) {
            return $this->fail('variant_id_required', 'נדרש מזהה וריאנט.', 422);
        }

        /*
         * "הוסף מוצר חד-פעמי" means ONE order. It used to append to dogs.addons_products,
         * which DraftOrderService ships every cycle — so a tasting box added once was billed
         * forever. It now goes into the subscription's next-order extras, cleared after the
         * next charge. A dog with no subscription has no next order to add to.
         */
        $subscription = $dog->subscription;

        if ($subscription === null) {
            return $this->fail('dog_not_in_subscription', 'הכלב אינו משויך למנוי.', 422);
        }

        $addons = collect(DraftOrderService::nextOrderAddons($subscription))->keyBy('variant_id');

        foreach ($variants as $variant) {
            $variantId = ShopifyId::numeric((string) $variant);
            $addons[$variantId] = ['variant_id' => $variantId, 'quantity' => ($addons[$variantId]['quantity'] ?? 0) + 1];
        }

        $subscription->forceFill(['next_order_addons' => $addons->values()->all()])->save();
        $this->rebuildUpcomingOrder($subscription);

        SystemLog::info('storefront', 'one-time extra added to the next order', [
            'variants' => $variants,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $customer->id]);

        return $this->ok(['dog' => StorefrontPresenter::dog($dog->fresh())]);
    }

    public function removeAddon(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $dog = $this->findOwnedDog($customer, $id);
        $this->guardDogBilling($dog);

        $variants = $this->idList($request, ['variantId', 'id', 'variantIds']);
        if ($variants === []) {
            return $this->fail('variant_id_required', 'נדרש מזהה וריאנט.', 422);
        }

        // From BOTH places: the old recurring add-ons on the dog, and the next-order extras.
        $dog->addons_products = array_values(array_diff($this->currentAddons($dog), $variants));
        $dog->save();

        if ($dog->subscription !== null) {
            $ids = array_map(fn ($v) => ShopifyId::numeric((string) $v), $variants);
            $kept = array_values(array_filter(
                DraftOrderService::nextOrderAddons($dog->subscription),
                fn (array $line) => ! in_array($line['variant_id'], $ids, true),
            ));
            $dog->subscription->forceFill(['next_order_addons' => $kept === [] ? null : $kept])->save();
            $this->rebuildUpcomingOrder($dog->subscription);
        }

        SystemLog::info('storefront', 'dog add-on removed', [
            'variants' => $variants,
        ], ['subscription_id' => $dog->subscription_id, 'customer_id' => $customer->id]);

        return $this->ok(['dog' => StorefrontPresenter::dog($dog->fresh())]);
    }

    /** Soft-remove: the dog is disabled, never hard-deleted (v1 behaviour). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $customer = $this->requireCustomer($request);
        $dog = $this->findOwnedDog($customer, $id);

        $dog->status = 'disable';
        $dog->subscription_status = null;
        $dog->save();

        SystemLog::info('storefront', 'dog removed by customer', [], [
            'subscription_id' => $dog->subscription_id,
            'customer_id' => $customer->id,
        ]);

        return $this->ok(['dog' => StorefrontPresenter::dog($dog->fresh())]);
    }

    /** Store the quiz answers; returns the public id the theme later links. */
    public function saveQuiz(Request $request): JsonResponse
    {
        $payload = $request->all();
        if ($payload === []) {
            return $this->fail('quiz_payload_required', 'לא נשלחו תשובות שאלון.', 422);
        }

        $customer = $request->attributes->get('customer');

        $quizDog = QuizDog::query()->create([
            'public_id' => (string) Str::uuid(),
            'customer_id' => $customer?->id,
            'payload' => $payload,
            'variant_refs' => $payload['variants'] ?? $payload['variant_refs'] ?? null,
        ]);

        SystemLog::info('storefront', 'quiz dog saved', ['quiz_dog' => $quizDog->public_id], [
            'customer_id' => $customer?->id,
        ]);

        /*
         * `id` is duplicated at the top of `data` deliberately. The theme's
         * extractDogIdFromResponse() walks payload.data.id FIRST and falls through to
         * payload.data.quizDog — which is an OBJECT, so a response carrying only the nested
         * shape gets "[object Object]" stored as the quiz-dog id and the cart sync breaks
         * silently. The flat field is what it actually reads.
         */
        return $this->ok(['id' => $quizDog->public_id, 'quizDog' => ['id' => $quizDog->public_id]]);
    }

    /** Turn a saved quiz into a real dog on the authenticated customer. */
    public function linkQuiz(Request $request, string $quizDogId): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        $quizDog = QuizDog::query()->where('public_id', $quizDogId)->first();
        if ($quizDog === null) {
            return $this->fail('not_found', 'שאלון לא נמצא.', 404);
        }

        $variants = $this->idList($request, ['variants', 'variantIds', 'variantId']);
        if ($variants === []) {
            return $this->fail('variants_required', 'נדרשים וריאנטים.', 422);
        }

        $answers = is_array($quizDog->payload) ? $quizDog->payload : [];

        $dog = Dog::query()->create([
            'customer_id' => $customer->id,
            'name' => $answers['name'] ?? null,
            'sex' => $answers['sex'] ?? null,
            'age' => $answers['age'] ?? null,
            'weight' => $answers['weight'] ?? null,
            'allergies' => $answers['allergies'] ?? null,
            'activity' => $answers['activity'] ?? null,
            'body' => $answers['body'] ?? null,
            'birth_date' => $answers['birth_date'] ?? null,
            'status' => 'active',
            'selected_variants' => $variants,
            'addons_products' => $this->idList($request, ['addonsVariantIds']),
        ]);

        $quizDog->forceFill([
            'customer_id' => $customer->id,
            'linked_dog_id' => $dog->id,
            'linked_at' => now(),
        ])->save();

        SystemLog::info('storefront', 'quiz dog linked to customer', [
            'quiz_dog' => $quizDog->public_id,
            'variants' => $variants,
        ], ['customer_id' => $customer->id]);

        return $this->ok(['dog' => StorefrontPresenter::dog($dog)]);
    }

    /** @return list<string> */
    private function currentAddons(Dog $dog): array
    {
        return StorefrontPresenter::dog($dog)['addons_products'];
    }

    /**
     * Billing-affecting dog writes inherit the subscription's card-update wall.
     */
    private function guardDogBilling(Dog $dog): void
    {
        if ($dog->subscription !== null) {
            $this->guardCardUpdate($dog->subscription);
        }
    }

    /** Best-effort: the DB is the truth and the draft follows; the next refresh catches up. */
    private function rebuildUpcomingOrder(Subscription $subscription): void
    {
        try {
            app(DraftOrderService::class)->refresh($subscription->fresh());
        } catch (Throwable $e) {
            SystemLog::warning('shopify', 'could not rebuild the upcoming order after a customer edit', [
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        }
    }
}
