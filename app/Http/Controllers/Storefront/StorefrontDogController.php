<?php

namespace App\Http\Controllers\Storefront;

use App\Models\Dog;
use App\Models\QuizDog;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

        $dog->addons_products = array_values(array_unique([
            ...$this->currentAddons($dog),
            ...$variants,
        ]));
        $dog->save();

        SystemLog::info('storefront', 'dog add-on added', [
            'variants' => $variants,
        ], ['subscription_id' => $dog->subscription_id, 'customer_id' => $customer->id]);

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

        $dog->addons_products = array_values(array_diff($this->currentAddons($dog), $variants));
        $dog->save();

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

        return $this->ok(['quizDog' => ['id' => $quizDog->public_id]]);
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
}
