<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SystemLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Shopify customer webhooks (SYSTEM-MAP §3.1) — keep the local customer record in
 * step with Shopify. HMAC-guarded (not the API secret). Idempotent upserts.
 */
class CustomerWebhookController extends Controller
{
    public function created(Request $request): JsonResponse
    {
        return $this->upsert($request, 'created');
    }

    public function updated(Request $request): JsonResponse
    {
        return $this->upsert($request, 'updated');
    }

    public function deleted(Request $request): JsonResponse
    {
        $shopifyId = (string) ($request->input('id') ?? '');

        if ($shopifyId === '') {
            return response()->json(['ok' => true, 'action' => 'skipped_missing_id']);
        }

        try {
            $customer = Customer::query()->where('shopify_customer_id', $shopifyId)->first();

            if ($customer === null) {
                return response()->json(['ok' => true, 'action' => 'noop_not_found']);
            }

            // Never hard-delete: subscriptions and money history hang off this row.
            $customer->forceFill([
                'meta' => array_merge((array) $customer->meta, [
                    'shopify_deleted_at' => now()->toIso8601String(),
                ]),
            ])->save();

            SystemLog::warning('webhook', 'customer deleted in Shopify (marked, not removed)', [
                'shopify_customer_id' => $shopifyId,
            ], ['customer_id' => $customer->id]);

            return response()->json(['ok' => true, 'action' => 'soft_deleted']);
        } catch (Throwable $e) {
            SystemLog::error('webhook', 'customer delete failed', ['message' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'persist_failed'], 500);
        }
    }

    private function upsert(Request $request, string $event): JsonResponse
    {
        $payload = $request->all();
        $shopifyId = (string) ($payload['id'] ?? '');

        if ($shopifyId === '') {
            return response()->json(['ok' => true, 'action' => 'skipped_invalid_payload']);
        }

        try {
            $address = (array) ($payload['default_address'] ?? []);

            $customer = Customer::query()->updateOrCreate(
                ['shopify_customer_id' => $shopifyId],
                array_filter([
                    'email' => $payload['email'] ?? null,
                    'first_name' => $payload['first_name'] ?? ($address['first_name'] ?? null),
                    'last_name' => $payload['last_name'] ?? ($address['last_name'] ?? null),
                    'phone' => $payload['phone'] ?? ($address['phone'] ?? null),
                    'address1' => $address['address1'] ?? null,
                    'address2' => $address['address2'] ?? null,
                    'city' => $address['city'] ?? null,
                    'province' => $address['province'] ?? null,
                    'country' => $address['country'] ?? null,
                    'zip' => $address['zip'] ?? null,
                ], fn ($v) => $v !== null),
            );

            SystemLog::info('webhook', "customer {$event}", [
                'shopify_customer_id' => $shopifyId,
            ], ['customer_id' => $customer->id]);

            return response()->json([
                'ok' => true,
                'action' => $customer->wasRecentlyCreated ? 'created' : 'updated',
            ]);
        } catch (Throwable $e) {
            SystemLog::error('webhook', "customer {$event} failed", ['message' => $e->getMessage()]);

            return response()->json(['ok' => false, 'error' => 'sync_failed'], 500);
        }
    }
}
