<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

/**
 * Verifies an App Bridge session token — the short-lived HS256 JWT Shopify Admin
 * hands an embedded app to prove WHO is looking and from WHICH shop.
 *
 * Self-contained (no JWT library): the algorithm is fixed and the claim set is small,
 * and a hand-rolled verifier that pins `alg` is safer here than a general-purpose
 * decoder that can be talked into `none`.
 *
 * Fails CLOSED — any doubt returns null. Validated: the HS256 signature over
 * header.payload with the app secret; `aud` equals our API key; `exp`/`nbf` within a
 * few seconds of now; and `iss` and `dest` naming the same shop.
 *
 * These tokens live about a minute and are never stored. They authenticate the
 * REQUEST; the offline token from OAuth is what talks to the Admin API.
 */
final class SessionTokenVerifier
{
    private const ALG = 'HS256';

    private const LEEWAY_SECONDS = 5;

    /**
     * @return array<string, mixed>|null validated claims, or null on any failure
     */
    public function verify(string $jwt, ?string $secret = null, ?string $apiKey = null): ?array
    {
        $secret ??= (string) config('shopify.api_secret', '');
        $apiKey ??= (string) config('shopify.api_key', '');

        if ($secret === '' || $apiKey === '') {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeSegment($encodedHeader);
        $claims = $this->decodeSegment($encodedPayload);
        if ($header === null || $claims === null) {
            return null;
        }

        // Algorithm pinned. Never let the token nominate its own — that is how a
        // verifier gets talked into accepting `none`.
        if (($header['alg'] ?? '') !== self::ALG) {
            return null;
        }

        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $encodedHeader.'.'.$encodedPayload, $secret, true)
        );
        if (! hash_equals($expected, $encodedSignature)) {
            return null;
        }

        $now = time();
        if ((string) ($claims['aud'] ?? '') !== $apiKey) {
            return null;
        }
        if (isset($claims['exp']) && $now >= ((int) $claims['exp'] + self::LEEWAY_SECONDS)) {
            return null;
        }
        if (isset($claims['nbf']) && $now < ((int) $claims['nbf'] - self::LEEWAY_SECONDS)) {
            return null;
        }

        $issHost = $this->host((string) ($claims['iss'] ?? ''));
        $destHost = $this->host((string) ($claims['dest'] ?? ''));
        if ($issHost === '' || $destHost === '' || $issHost !== $destHost) {
            return null;
        }

        return $claims;
    }

    /** The shop this token was minted for, taken ONLY from the verified dest claim. */
    public function shopDomain(array $claims): string
    {
        return $this->host((string) ($claims['dest'] ?? ''));
    }

    /**
     * The *.myshopify.com host named by an iss/dest claim, whatever shape it arrives in.
     *
     * App Bridge sends full URLs (https://{shop}/admin); other surfaces send the bare
     * host — and parse_url() reads a scheme-less string as a PATH, so a bare host
     * yields no host at all. Handling both is the difference between working and
     * rejecting every token from those surfaces as if it were forged.
     */
    private function host(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST) ?: explode('/', preg_replace('#^https?://#i', '', $value))[0];
        $host = strtolower(trim((string) $host));

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $host) === 1 ? $host : '';
    }

    /** @return array<string, mixed>|null */
    private function decodeSegment(string $segment): ?array
    {
        $json = $this->base64UrlDecode($segment);
        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
