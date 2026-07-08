<?php

namespace App\Modules\MillsSubscriptions\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * 019 SMS adapter (D13). 019 exposes an XML-over-HTTP API. This builds the
 * request from `config('sms.019')`; when credentials are absent it fails soft
 * (logs + returns false) so the OTP flow can fall back to email without error.
 *
 * NOTE: the exact 019 XML schema is confirmed against the account once Aviad
 * provides credentials — the structure below matches 019's documented
 * send-SMS envelope and is guarded behind the config check.
 */
class Sms019Sender implements SmsSender
{
    public function send(string $phone, string $message): bool
    {
        $cfg = (array) config('sms.019', []);
        $username = (string) ($cfg['username'] ?? '');
        $token = (string) ($cfg['token'] ?? '');
        $sender = (string) ($cfg['sender'] ?? 'Mills');
        $baseUrl = (string) ($cfg['base_url'] ?? 'https://019sms.co.il/api');

        if ($username === '' || $token === '') {
            Log::warning('sms.019.not_configured', ['phone_fingerprint' => substr(hash('sha256', $phone), 0, 12)]);

            return false;
        }

        $xml = $this->buildXml($username, $token, $sender, $phone, $message);

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/xml'])
                ->withBody($xml, 'application/xml')
                ->timeout(15)
                ->post($baseUrl);

            $ok = $response->successful() && ! str_contains($response->body(), '<status>-');
            if (! $ok) {
                Log::warning('sms.019.send_failed', ['http' => $response->status()]);
            }

            return $ok;
        } catch (\Throwable $e) {
            Log::error('sms.019.exception', ['message' => $e->getMessage()]);

            return false;
        }
    }

    private function buildXml(string $username, string $token, string $sender, string $phone, string $message): string
    {
        $phone = htmlspecialchars($phone, ENT_XML1);
        $message = htmlspecialchars($message, ENT_XML1);
        $sender = htmlspecialchars($sender, ENT_XML1);

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
