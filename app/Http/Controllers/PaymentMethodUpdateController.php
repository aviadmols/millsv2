<?php

namespace App\Http\Controllers;

use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use RuntimeException;

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
                'message' => $e->getMessage() === 'session_expired'
                    ? 'פג תוקף הקישור. יש להתחיל את עדכון אמצעי התשלום מחדש.'
                    : 'עדכון אמצעי התשלום נכשל. נסה שוב.',
            ]);
        }
    }
}
