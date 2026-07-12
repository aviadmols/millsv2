<?php

namespace App\Http\Controllers\Api;

use App\Models\Dog;
use App\Models\QuizDog;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Dogs + the quiz (SYSTEM-MAP §3.1), reachable at /api/dogs/* and the legacy
 * /shopify/dog/* aliases. POST /api/dogs/quiz is what the theme's quiz page calls.
 */
class DogApiController extends AbstractApiController
{
    /** Health ping kept from v1 (the theme uses it to check the base URL). */
    public function hello(): JsonResponse
    {
        return response()->json(['message' => 'Mills subscriptions API', 'service' => 'mills-v2']);
    }

    /** The quiz: store the answers, hand back the id the theme links later. */
    public function saveQuiz(Request $request): JsonResponse
    {
        $payload = $request->all();

        if ($payload === []) {
            return response()->json(['message' => 'Quiz payload is required'], 422);
        }

        $quizDog = QuizDog::query()->create([
            'public_id' => (string) ($payload['quizDogId'] ?? Str::uuid()),
            'payload' => $payload,
            'variant_refs' => $payload['variants'] ?? $payload['variant_refs'] ?? null,
        ]);

        SystemLog::info('api', 'quiz dog saved', ['quiz_dog' => $quizDog->public_id]);

        return response()->json(['id' => $quizDog->public_id]);
    }

    /** Turn a saved quiz into a real dog on a customer. */
    public function linkQuiz(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customerId' => ['required', 'string'],
            'quizDogId' => ['required', 'string'],
        ]);

        $customer = $this->resolveCustomer($data['customerId']);

        $quizDog = QuizDog::query()->where('public_id', $data['quizDogId'])->first();
        if ($quizDog === null) {
            return response()->json(['message' => 'Quiz dog not found'], 404);
        }

        $answers = is_array($quizDog->payload) ? $quizDog->payload : [];
        $variants = $this->idList($request->all(), ['variants', 'variantIds'])
            ?: $this->idList(['v' => $quizDog->variant_refs ?? []], ['v']);

        $dog = Dog::query()->create([
            'customer_id' => $customer->id,
            'name' => $answers['name'] ?? null,
            'sex' => $answers['sex'] ?? null,
            'age' => $answers['age'] ?? null,
            'weight' => $answers['weight'] ?? null,
            'allergies' => $answers['allergies'] ?? null,
            'activity' => $answers['activity'] ?? null,
            'body' => $answers['body'] ?? null,
            'status' => 'active',
            'selected_variants' => $variants,
            'addons_products' => $this->idList($request->all(), ['addonsVariantIds']),
        ]);

        $quizDog->forceFill([
            'customer_id' => $customer->id,
            'linked_dog_id' => $dog->id,
            'linked_at' => now(),
        ])->save();

        SystemLog::info('api', 'quiz dog linked', [
            'quiz_dog' => $quizDog->public_id,
        ], ['customer_id' => $customer->id]);

        return response()->json(StorefrontPresenter::dog($dog));
    }

    public function addAddon(Request $request): JsonResponse
    {
        [$dog, $variants] = $this->dogAndVariants($request);
        if ($variants === []) {
            return response()->json(['message' => 'Variant id is required'], 422);
        }

        $dog->addons_products = array_values(array_unique([
            ...StorefrontPresenter::dog($dog)['addons_products'],
            ...$variants,
        ]));
        $dog->save();

        return response()->json(StorefrontPresenter::dog($dog->fresh()));
    }

    public function removeAddon(Request $request): JsonResponse
    {
        [$dog, $variants] = $this->dogAndVariants($request);
        if ($variants === []) {
            return response()->json(['message' => 'Variant id is required'], 422);
        }

        $dog->addons_products = array_values(array_diff(
            StorefrontPresenter::dog($dog)['addons_products'],
            $variants,
        ));
        $dog->save();

        return response()->json(StorefrontPresenter::dog($dog->fresh()));
    }

    public function changeSubscriptionVariant(Request $request): JsonResponse
    {
        $dog = $this->resolveDog((string) $request->input('dogId', ''));
        $variants = $this->idList($request->all(), ['variantId', 'variantIds']);

        if ($variants === []) {
            return response()->json(['message' => 'Variant id is required'], 422);
        }

        $dog->selected_variants = $variants;
        $dog->save();

        return response()->json(StorefrontPresenter::dog($dog->fresh()));
    }

    public function changeSubscriptionStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dogId' => ['required', 'string'],
            'status' => ['required', 'string'],
        ]);

        $dog = $this->resolveDog($data['dogId']);
        $dog->forceFill(['subscription_status' => $data['status']])->save();

        return response()->json(StorefrontPresenter::dog($dog->fresh()));
    }

    public function changeStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dogId' => ['required', 'string'],
            'status' => ['required', 'string'],
        ]);

        $dog = $this->resolveDog($data['dogId']);
        $dog->forceFill(['status' => $data['status']])->save();

        return response()->json(StorefrontPresenter::dog($dog->fresh()));
    }

    public function removeFromCustomer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'dogId' => ['required', 'string'],
            'customerId' => ['required', 'string'],
        ]);

        $dog = $this->resolveDog($data['dogId']);
        $customer = $this->resolveCustomer($data['customerId']);

        if ((int) $dog->customer_id !== (int) $customer->id) {
            return response()->json(['message' => 'Dog does not belong to this customer'], 422);
        }

        // Soft-remove, exactly as v1 — history is never destroyed.
        $dog->forceFill([
            'status' => 'disable',
            'subscription_id' => null,
            'subscription_status' => null,
        ])->save();

        SystemLog::info('api', 'dog removed from customer', [], [
            'customer_id' => $customer->id,
        ]);

        return response()->json(StorefrontPresenter::dog($dog->fresh()));
    }

    public function update(Request $request): JsonResponse
    {
        $dog = $this->resolveDog((string) $request->input('dogId', ''));

        $updates = $request->input('updates');
        $updates = is_array($updates) ? $updates : [];
        $updates = array_intersect_key($updates, array_flip([
            'name', 'sex', 'age', 'weight', 'allergies', 'activity', 'body',
            'birth_date', 'avatar', 'double_food', 'calories_per_day',
        ]));

        if ($updates === []) {
            return response()->json(['message' => 'updates is required'], 422);
        }

        $dog->fill($updates)->save();

        return response()->json(StorefrontPresenter::dog($dog->fresh()));
    }

    /** @return array{0: Dog, 1: list<string>} */
    private function dogAndVariants(Request $request): array
    {
        return [
            $this->resolveDog((string) $request->input('dogId', '')),
            $this->idList($request->all(), ['variantId', 'id', 'variantIds']),
        ];
    }
}
