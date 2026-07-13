<?php

use App\Http\Controllers\Api\CronApiController;
use App\Http\Controllers\Api\DogApiController;
use App\Http\Controllers\Api\OrderApiController;
use App\Http\Controllers\Api\SubscriptionApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Legacy NestJS-compat aliases (SYSTEM-MAP §3.2), mounted at ROOT — these are the
 * exact paths the existing Shopify theme already calls, so pointing its base URL
 * at this app is all that is needed. Same controllers, same auth, same responses
 * as /api/*; only the paths differ.
 *
 * Note the two quirks faithfully preserved from v1:
 *   - PATCH/DELETE on the subscription COLLECTION take the id as a ?id= QUERY param.
 *   - Dog routes use snake_case verbs (change_subscription_variant, …).
 */

// Same as /api: a path id may be numeric or a full Shopify GID (which has slashes).
$idPattern = '[0-9]+|gid:\/\/shopify\/[A-Za-z]+\/[0-9]+';

Route::middleware('api.secret')
    ->where(['id' => $idPattern, 'customerId' => $idPattern, 'draftOrderId' => $idPattern])
    ->group(function () {

    Route::prefix('shopify/subscription')->group(function () {
        Route::get('active/charge-cycle-today', [SubscriptionApiController::class, 'dueToday']);
        Route::post('from-order', [SubscriptionApiController::class, 'createFromOrder']);

        Route::get('customer/{customerId}', [SubscriptionApiController::class, 'byCustomer']);
        Route::get('status/{status}', [SubscriptionApiController::class, 'byStatus']);
        Route::get('draft-order/{draftOrderId}', [SubscriptionApiController::class, 'byDraftOrder']);

        Route::post('{id}/create-draft-order', [SubscriptionApiController::class, 'createDraftOrder']);
        Route::patch('{id}/update-draft-order', [SubscriptionApiController::class, 'updateDraftOrder']);
        Route::get('{id}/draft-order', [SubscriptionApiController::class, 'getDraftOrder']);

        Route::get('{id}/products', [SubscriptionApiController::class, 'products']);
        Route::patch('{id}/add-dog', [SubscriptionApiController::class, 'addDog']);
        Route::patch('{id}/remove-dog', [SubscriptionApiController::class, 'removeDog']);

        Route::post('/', [SubscriptionApiController::class, 'store']);
        Route::get('/', [SubscriptionApiController::class, 'index']);

        // Collection-level PATCH/DELETE addressed by ?id= (v1 quirk).
        Route::patch('/', function (Request $request, SubscriptionApiController $controller) {
            $id = (string) $request->query('id', '');
            abort_if($id === '', 422, 'Subscription id is required');

            return $controller->update($request, $id);
        });
        Route::delete('/', function (Request $request, SubscriptionApiController $controller) {
            $id = (string) $request->query('id', '');
            abort_if($id === '', 422, 'Subscription id is required');

            return $controller->destroy($id);
        });

        Route::get('{id}', [SubscriptionApiController::class, 'show']);
    });

    Route::prefix('shopify/dog')->group(function () {
        Route::get('/', [DogApiController::class, 'hello']);
        Route::post('save-quiz-dog', [DogApiController::class, 'saveQuiz']);
        Route::post('recommend', [DogApiController::class, 'recommend']);
        Route::post('link-quiz-dog-customer', [DogApiController::class, 'linkQuiz']);
        Route::patch('add-addon', [DogApiController::class, 'addAddon']);
        Route::patch('remove-addon', [DogApiController::class, 'removeAddon']);
        Route::patch('change_subscription_variant', [DogApiController::class, 'changeSubscriptionVariant']);
        Route::patch('change_subscription_status', [DogApiController::class, 'changeSubscriptionStatus']);
        Route::post('change_status', [DogApiController::class, 'changeStatus']);
        Route::post('remove-dog-from-customer', [DogApiController::class, 'removeFromCustomer']);
        Route::patch('update', [DogApiController::class, 'update']);
    });

    Route::prefix('order')->group(function () {
        Route::get('/', [OrderApiController::class, 'hello']);
        Route::post('create-draft-order', [OrderApiController::class, 'createDraft']);
        Route::get('subscription', [OrderApiController::class, 'processBilling']);

        Route::post('cron/init', [CronApiController::class, 'init']);
        Route::post('cron/start', [CronApiController::class, 'start']);
        Route::post('cron/stop', [CronApiController::class, 'stop']);
        Route::post('cron/trigger', [CronApiController::class, 'trigger']);
        Route::get('cron/status', [CronApiController::class, 'status']);
    });
});
