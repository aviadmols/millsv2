<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyStorefrontToken;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared plumbing for the personal area (SYSTEM-MAP §3.3): the frozen envelope,
 * ownership lookups, and the card-update gate.
 *
 * Frozen behaviours preserved from v1:
 *  - Envelope: {ok:true,data:…} / {ok:false,error:<code>,message:<he>}.
 *  - A record the customer does not own is reported as 404 `not_found` (never 403)
 *    so existence is not leaked.
 *  - Billing-affecting writes on a subscription that still needs a card update are
 *    rejected 403 `icount_requires_card_update` — the exact code the theme keys on.
 *  - Ids accept the legacy Shopify GID or the numeric id, interchangeably.
 */
abstract class AbstractStorefrontController extends Controller
{
    /** @param array<string, mixed> $data */
    protected function ok(array $data): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $data]);
    }

    /** @param array<string, mixed> $extra */
    protected function fail(string $error, string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $error, 'message' => $message] + $extra, $status);
    }

    protected function requireCustomer(Request $request): Customer
    {
        $customer = $request->attributes->get(VerifyStorefrontToken::REQUEST_ATTR_CUSTOMER);

        if (! $customer instanceof Customer) {
            throw new HttpResponseException(
                $this->fail('unauthenticated', 'נדרשת התחברות.', 401),
            );
        }

        return $customer;
    }

    /** Resolve a subscription the customer owns — by numeric id OR legacy GID. */
    protected function findOwnedSubscription(Customer $customer, string $id): Subscription
    {
        $subscription = $customer->subscriptions()
            ->where(fn ($q) => $q->where('id', ctype_digit($id) ? (int) $id : 0)
                ->orWhere('legacy_shopify_gid', $id))
            ->first();

        if ($subscription === null) {
            throw new HttpResponseException(
                $this->fail('not_found', 'המנוי לא נמצא.', 404),
            );
        }

        return $subscription;
    }

    /** Resolve a dog the customer owns — by numeric id OR legacy GID. */
    protected function findOwnedDog(Customer $customer, string $id): Dog
    {
        $dog = $customer->dogs()
            ->where(fn ($q) => $q->where('id', ctype_digit($id) ? (int) $id : 0)
                ->orWhere('legacy_shopify_gid', $id))
            ->first();

        if ($dog === null) {
            throw new HttpResponseException(
                $this->fail('not_found', 'הכלב לא נמצא.', 404),
            );
        }

        return $dog;
    }

    /**
     * The card-update wall. Any billing-affecting write (subscription changes,
     * flavor/add-on changes) is blocked until a PayMe card is on file.
     */
    protected function guardCardUpdate(Subscription $subscription): void
    {
        if ($subscription->payment_state !== PaymentState::NEEDS_CARD_UPDATE) {
            return;
        }

        throw new HttpResponseException(
            $this->fail(
                'icount_requires_card_update',
                'יש לעדכן אמצעי תשלום לפני ביצוע שינויים במנוי.',
                403,
                ['requires_card_update' => true, 'subscription_id' => $subscription->id],
            ),
        );
    }

    /**
     * Collect ids from the modern single field or the legacy plural field.
     * (v1 accepted dogId | dogIds[] and variantId | id | variantIds[].)
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    protected function idList(Request $request, array $keys): array
    {
        $ids = [];

        foreach ($keys as $key) {
            $value = $request->input($key);
            if ($value === null || $value === '') {
                continue;
            }
            foreach ((array) $value as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    $ids[] = (string) $item;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
