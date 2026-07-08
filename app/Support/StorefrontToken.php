<?php

namespace App\Support;

/**
 * The frozen v1 storefront token (SYSTEM-MAP §3.3): `<subject>.<unix_ts>.<hmac>`,
 * HMAC-SHA256 over `<subject>.<ts>` with STOREFRONT_TOKEN_SECRET. The theme mints
 * this in Liquid; OTP verification mints the identical shape (ARCHITECTURE.md §6,
 * D8) so every existing storefront endpoint keeps working unchanged.
 *
 * `subject` is the Shopify customer numeric id.
 */
final class StorefrontToken
{
    private const SHAPE = '/^(\d+)\.(\d+)\.([a-f0-9]{64})$/';

    /** Clock-skew tolerance for freshly-minted tokens (seconds). */
    private const SKEW = 60;

    public static function mint(string $subject, ?int $at = null): string
    {
        $ts = $at ?? time();
        $payload = $subject.'.'.$ts;

        return $payload.'.'.hash_hmac('sha256', $payload, self::secret());
    }

    /**
     * Returns the verified subject (Shopify customer numeric id) or null.
     */
    public static function verify(string $token, ?int $maxAge = null): ?string
    {
        $secret = self::secret();
        if ($secret === '') {
            return null;
        }

        if (preg_match(self::SHAPE, trim($token), $m) !== 1) {
            return null;
        }

        [, $subject, $ts, $sig] = $m;

        $expected = hash_hmac('sha256', $subject.'.'.$ts, $secret);
        if (! hash_equals($expected, $sig)) {
            return null;
        }

        $age = time() - (int) $ts;
        $max = $maxAge ?? (int) config('shopify.storefront_token_max_age', 86400);
        if ($age < -self::SKEW || $age > $max) {
            return null;
        }

        return $subject;
    }

    private static function secret(): string
    {
        return (string) config('shopify.storefront_token_secret', '');
    }
}
