<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Modules\MillsSubscriptions\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OTP login for the personal area (ARCHITECTURE.md §6). request() issues a code
 * (email now, SMS via 019 later); verify() returns the frozen v1-format
 * storefront token the theme uses for every /storefront/* call.
 */
class OtpAuthController extends Controller
{
    public function request(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:phone', 'nullable', 'email'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'in:email,sms'],
        ]);

        [$destination, $channel] = $this->resolveTarget($data);

        $result = $otp->request($destination, $channel);

        return response()->json(
            array_filter([
                'ok' => $result['ok'],
                'error' => $result['error'] ?? null,
                'retry_after_seconds' => $result['retry_after_seconds'] ?? null,
            ], static fn ($v) => $v !== null),
            $result['ok'] ? 200 : 429,
        );
    }

    public function verify(Request $request, OtpService $otp): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:phone', 'nullable', 'email'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:32'],
            'channel' => ['nullable', 'in:email,sms'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        [$destination, $channel] = $this->resolveTarget($data);

        $result = $otp->verify($destination, (string) $data['code'], $channel);

        if (! $result['ok']) {
            return response()->json(['ok' => false, 'error' => $result['error']], 401);
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'token' => $result['token'],
                'customer' => $result['customer'],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string}
     */
    private function resolveTarget(array $data): array
    {
        $channel = $data['channel'] ?? ((! empty($data['phone']) && empty($data['email'])) ? 'sms' : 'email');
        $destination = $channel === 'sms' ? (string) ($data['phone'] ?? '') : (string) ($data['email'] ?? '');

        return [$destination, $channel];
    }
}
