<?php

namespace App\Http\Middleware;

use App\Models\Customer;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\LegacyCustomerImporter;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\StorefrontToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates the personal area (SYSTEM-MAP §3.3). Reads the Bearer token,
 * verifies the frozen HMAC, resolves the local Customer by shopify_customer_id
 * (the DB is the source of truth), and attaches it as the request `customer`
 * attribute. Errors use the frozen envelope.
 */
class VerifyStorefrontToken
{
    // === CONSTANTS ===
    public const REQUEST_ATTR_CUSTOMER = 'customer';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('shopify.storefront_token_secret', '');
        if ($secret === '') {
            // Fail closed in production; allow a dev bypass header locally.
            if (app()->isProduction()) {
                return $this->deny('not_configured', 503);
            }
            $devId = $request->header('X-Mills-Dev-Customer-Id');
            if ($devId && ($c = Customer::query()->where('shopify_customer_id', $devId)->first())) {
                $request->attributes->set(self::REQUEST_ATTR_CUSTOMER, $c);

                return $next($request);
            }
        }

        $token = $request->bearerToken();
        if (! $token) {
            return $this->deny('missing_token');
        }

        // An admin previewing a customer's personal area gets a `pv.` token: short-lived
        // and READ-ONLY. Support staff have no business writing as the customer, and a
        // tool that can silently alter someone's subscription is a liability.
        if (StorefrontToken::isPreview($token)) {
            $subject = StorefrontToken::verifyPreview($token);

            if ($subject === null) {
                return $this->deny('invalid_token');
            }

            if (! $request->isMethod('GET') && ! $this->isAllowedInPreview($request)) {
                return $this->deny('preview_read_only', 403);
            }
        } else {
            $subject = StorefrontToken::verify($token);
        }

        if ($subject === null) {
            return $this->deny('invalid_token');
        }

        $customer = Customer::query()->where('shopify_customer_id', $subject)->first();

        /*
         * A valid token for a customer we have never imported is not an intruder — it is a
         * real Shopify customer opening their personal area for the first time. The token is
         * minted server-side by Liquid only for a logged-in Shopify session, so the identity
         * is already proven; refusing them meant most of the store's customers hit a blank
         * 401 the moment they opened /account, while the SMS login imported the very same
         * people happily. Same importer, same result: their details and any legacy-note
         * subscription come across, behind the card-update wall.
         *
         * Previews are deliberately excluded: a read-only preview must not write rows, and
         * the admin flows that mint pv. tokens import the customer before minting.
         */
        if ($customer === null && ! StorefrontToken::isPreview($token)) {
            $customer = $this->importFirstTimer($subject);
        }

        if ($customer === null) {
            return $this->deny('customer_not_found');
        }

        $request->attributes->set(self::REQUEST_ATTR_CUSTOMER, $customer);

        return $next($request);
    }

    private function importFirstTimer(string $shopifyCustomerId): ?Customer
    {
        try {
            $result = app(LegacyCustomerImporter::class)->import($shopifyCustomerId, null, Timeline::ACTOR_CUSTOMER);
        } catch (Throwable $e) {
            // Shopify unreachable — deny rather than guess; the next visit will try again.
            SystemLog::warning('storefront', 'first-visit import failed', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if ($result['customer_id'] === null) {
            return null;   // Shopify itself does not know this id — THAT is an invalid subject
        }

        SystemLog::info('storefront', 'customer imported on their first visit to the personal area', [
            'status' => $result['status'],
        ], ['customer_id' => $result['customer_id']]);

        return Customer::query()->find($result['customer_id']);
    }

    /**
     * The one write a preview may perform: starting a card update.
     *
     * Everything else stays blocked, because support has no business changing someone's plan
     * behind their back. This is the exception because it cannot do that: it opens PayMe's
     * hosted page, the CUSTOMER types the card there, and the only possible outcome is that a
     * blocked subscription becomes billable again. Refusing it meant the one thing support is
     * on the phone to help with was the one thing the preview could not do.
     */
    private function isAllowedInPreview(Request $request): bool
    {
        return $request->isMethod('POST')
            && $request->routeIs('storefront.me.payment-method.payme.session');
    }

    private function deny(string $reason, int $status = 401): Response
    {
        return response()->json([
            'ok' => false,
            'error' => 'unauthenticated',
            'reason' => $reason,
        ], $status);
    }
}
