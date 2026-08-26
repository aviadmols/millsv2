<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * PayMe card-update return page (SYSTEM-MAP §3.4). No login: the single-use
 * session_id (15-minute TTL, minted for the authenticated customer) IS the
 * credential. Landing here means the shopper finished entering the card on
 * PayMe's hosted page, so we exchange the sale for a reusable buyer_key and lift
 * the card-update wall.
 */
class PaymentMethodUpdateController extends Controller
{
    /**
     * The Hosted Fields card form — the v1 page, ported. OUR layout and payer fields;
     * the card inputs themselves are PayMe-hosted iframes, so card data never enters
     * this app. GET only peeks at the session; the token POST is what consumes it.
     */
    public function form(Request $request, CardUpdateService $cardUpdate): View
    {
        $sessionId = (string) $request->query('session_id', '');
        $session = $sessionId !== '' ? $cardUpdate->sessionForForm($sessionId) : null;
        $customer = $session !== null ? Customer::query()->find($session['customer_id'] ?? null) : null;

        return view('payment-method.update', [
            'sessionAlive' => $session !== null,
            'sessionId' => $sessionId,
            'subscriptionId' => $session['subscription_id'] ?? null,
            'hostedFields' => $cardUpdate->hostedFieldsConfig(),
            'submitUrl' => route('storefront.payment-method.payme-token'),
            'customer' => $customer,
        ]);
    }

    /** The form's JSON POST: the PayMe token for the session's customer. */
    public function storeToken(Request $request, CardUpdateService $cardUpdate): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:64'],
            'token' => ['required', 'string', 'max:191'],
            'masked_card' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $cardUpdate->completeWithToken(
                (string) $data['session_id'],
                (string) $data['token'],
                (string) ($data['masked_card'] ?? ''),
            );

            return response()->json([
                'ok' => true,
                'subscription_id' => $result['subscription_id'],
                'message' => 'אמצעי התשלום עודכן בהצלחה.',
            ]);
        } catch (RuntimeException $e) {
            SystemLog::warning('billing', 'hosted-fields card update rejected', [
                'reason' => $e->getMessage(),
            ]);

            [$status, $message] = match ($e->getMessage()) {
                'session_expired' => [410, 'פג תוקף הקישור. יש להתחיל את עדכון אמצעי התשלום מחדש.'],
                'invalid_token' => [422, 'הטוקן שהתקבל אינו תקין. יש לנסות שוב.'],
                default => [422, 'שמירת הכרטיס נכשלה. יש לנסות שוב.'],
            };

            return response()->json(['ok' => false, 'message' => $message], $status);
        }
    }

    public function callback(Request $request, CardUpdateService $cardUpdate): View
    {
        $sessionId = (string) $request->query('session_id', '');

        if ($sessionId === '') {
            return view('payment-method.callback', [
                'ok' => false,
                'message' => 'הקישור אינו תקין.',
            ]);
        }

        try {
            $cardUpdate->consume($sessionId);

            return view('payment-method.callback', [
                'ok' => true,
                'message' => 'אמצעי התשלום עודכן בהצלחה.',
            ]);
        } catch (RuntimeException $e) {
            SystemLog::warning('billing', 'card-update callback failed', [
                'reason' => $e->getMessage(),
            ]);

            return view('payment-method.callback', [
                'ok' => false,
                'message' => match ($e->getMessage()) {
                    'session_expired' => 'פג תוקף הקישור. יש להתחיל את עדכון אמצעי התשלום מחדש.',
                    // The card details were taken by PayMe; only OUR confirmation failed.
                    // Telling this customer to "try again" sends them to re-enter a card
                    // that is already captured — and to a second verification charge.
                    'payme_rejected' => 'פרטי הכרטיס נקלטו, אך אישור העדכון מול חברת הסליקה נכשל. אין צורך להזין את הכרטיס שוב — אנו נשלים את העדכון וניצור קשר במידת הצורך.',
                    default => 'עדכון אמצעי התשלום נכשל. נסה שוב.',
                },
            ]);
        } catch (Throwable $e) {
            // A customer who has just typed a real card number must never meet a raw 500
            // page — whatever breaks in here, the answer is a page in Hebrew that says
            // what to do, and an admin-visible log entry that says what broke.
            SystemLog::error('billing', 'card-update callback crashed', [
                'session_id' => $sessionId,
                'exception' => $e->getMessage(),
            ]);

            return view('payment-method.callback', [
                'ok' => false,
                'message' => 'אירעה שגיאה בעדכון אמצעי התשלום. אם הזנתם את פרטי הכרטיס — אין צורך להזין אותם שוב; אנו נטפל בעדכון.',
            ]);
        }
    }
}
