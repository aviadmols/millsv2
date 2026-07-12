<?php

namespace App\Http\Controllers\Storefront;

use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /storefront/me/payment-method/payme/session — open a PayMe card-update
 * session. Returns the frozen envelope the theme redirects to:
 * {session_id, mode, hosted_url, return_url, subscription_id, expires_in_seconds}.
 */
class StorefrontPaymentController extends AbstractStorefrontController
{
    public function createSession(Request $request, CardUpdateService $cardUpdate): JsonResponse
    {
        $customer = $this->requireCustomer($request);

        $subscription = null;
        if ($request->filled('subscription_id')) {
            $subscription = $this->findOwnedSubscription($customer, (string) $request->input('subscription_id'));
        }

        try {
            return $this->ok($cardUpdate->createSession($customer, $subscription));
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'no_subscription_found') {
                return $this->fail('no_subscription_found', 'לא נמצא מנוי לעדכון אמצעי תשלום.', 404);
            }

            SystemLog::error('billing', 'card-update session could not be created', [
                'reason' => $e->getMessage(),
            ], ['customer_id' => $customer->id]);

            return $this->fail('payme_unavailable', 'שירות התשלומים אינו זמין כעת. נסה שוב מאוחר יותר.', 502);
        }
    }
}
