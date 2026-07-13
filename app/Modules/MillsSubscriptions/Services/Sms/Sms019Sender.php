<?php

namespace App\Modules\MillsSubscriptions\Services\Sms;

use App\Models\SystemLog;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * 019 SMS adapter (D13) — the channel that carries the personal-area login code.
 *
 * 019 takes an XML body and authenticates with a bearer token in the HTTP header.
 * The previous version built the XML with a <username> but never sent the token
 * anywhere, so every send would have been rejected as unauthenticated.
 *
 * Fails soft: with no credentials it logs and returns false rather than throwing, so
 * a misconfigured SMS channel can never take the login flow down with it.
 */
class Sms019Sender implements SmsSender
{
    public function send(string $phone, string $message): bool
    {
        $cfg = (array) config('sms.019', []);
        $username = (string) ($cfg['username'] ?? '');
        $token = (string) ($cfg['token'] ?? '');
        $sender = (string) ($cfg['sender'] ?? 'Mills');
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? 'https://019sms.co.il/api'), '/');

        if ($username === '' || $token === '') {
            SystemLog::warning('otp', 'SMS not sent — 019 is not configured', [
                'hint' => 'Settings → SMS (019): username + token',
            ]);

            return false;
        }

        // 019 dials the local Israeli form.
        $destination = PhoneNumber::local($phone) ?? $phone;

        try {
            $response = Http::withToken($token)          // Authorization: Bearer <token>
                ->withHeaders(['Content-Type' => 'application/xml'])
                ->withBody($this->buildXml($username, $sender, $destination, $message), 'application/xml')
                ->timeout(15)
                ->post($baseUrl);

            $status = $this->statusFrom($response->body());
            $ok = $response->successful() && $status === 0;

            if (! $ok) {
                SystemLog::error('otp', 'SMS send failed at 019', [
                    'http' => $response->status(),
                    'provider_status' => $status,
                    // Never log the number itself.
                    'phone_fingerprint' => substr(hash('sha256', $destination), 0, 12),
                ]);
            }

            return $ok;
        } catch (Throwable $e) {
            SystemLog::error('otp', 'SMS send threw', ['message' => $e->getMessage()]);

            return false;
        }
    }

    /** 019 answers with <status>0</status> on success and a negative code on failure. */
    private function statusFrom(string $body): ?int
    {
        return preg_match('/<status>\s*(-?\d+)\s*<\/status>/i', $body, $m) === 1 ? (int) $m[1] : null;
    }

    private function buildXml(string $username, string $sender, string $phone, string $message): string
    {
        $username = htmlspecialchars($username, ENT_XML1);
        $sender = htmlspecialchars($sender, ENT_XML1);
        $phone = htmlspecialchars($phone, ENT_XML1);
        $message = htmlspecialchars($message, ENT_XML1);

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <sms>
          <user><username>{$username}</username></user>
          <source>{$sender}</source>
          <destinations><phone>{$phone}</phone></destinations>
          <message>{$message}</message>
          <add_unsubscribe>0</add_unsubscribe>
        </sms>
        XML;
    }
}
