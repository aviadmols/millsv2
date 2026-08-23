<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use Illuminate\Contracts\View\View;
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
