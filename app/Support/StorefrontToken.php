<?php

namespace App\Support;

/**
 * The frozen v1 storefront token (SYSTEM-MAP §3.3): `<subject>.<unix_ts>.<hmac>`,
 * HMAC-SHA256 over `<subject>.<ts>` with STOREFRONT_TOKEN_SECRET. The theme mints this
 * in Liquid; OTP verification mints the identical shape (ARCHITECTURE.md §6, D8) so every
 * existing storefront endpoint keeps working unchanged.
 *
 * `subject` is the Shopify customer numeric id.
 *
 * There is a second, deliberately weaker token: the PREVIEW token (`pv.` prefix), minted
 * when an admin opens a customer's personal area to see what the customer sees. It is
 * short-lived and READ-ONLY — an admin has no business writing as the customer, and a
 * support tool that can silently change someone's subscription is a liability, not a
 * feature. VerifyStorefrontToken enforces both properties.
 */
final class StorefrontToken
{
    private const SHAPE = '/^(\d+)\.(\d+)\.([a-f0-9]{64})$/';

    private const PREVIEW_SHAPE = '/^pv\.(\d+)\.(\d+)\.([a-f0-9]{64})$/';

    private const PREVIEW_PREFIX = 'pv';

    /** A preview is for looking at a screen, not for holding onto. */
    public const PREVIEW_MAX_AGE = 1800;   // 30 minutes

    /** Clock-skew tolerance for freshly-minted tokens (seconds). */
    private const SKEW = 60;

    /**
     * Slide the login forward once a token is a day old.
     *
     * The token carries its own issue time and nothing else, so the ONLY way a returning
     * customer stays logged in is to hand them a fresher one. Re-minting on every request
     * would be wasteful and would churn the stored value constantly; a day is often enough
     * that anyone visiting within the max age keeps their session indefinitely, while a
     * token that leaks still dies on the original 30-day clock unless it is used.
     */
    public const REFRESH_AFTER = 86400;

    /**
     * A fresher token for a still-valid one, or null when it is not due for renewal.
     *
     * Deliberately re-verifies rather than trusting the caller: this mints credentials, and
     * a helper that mints from an unverified string is one refactor away from an oracle
     * that upgrades a forged token into a real one.
     */
    public static function renew(string $token, ?int $refreshAfter = null): ?string
    {
        // Previews are a support tool with a 30-minute life BY DESIGN — never extend one.
        if (self::isPreview($token)) {
            return null;
        }

        $subject = self::verify($token);
        if ($subject === null) {
            return null;
        }

        if (preg_match(self::SHAPE, trim($token), $m) !== 1) {
            return null;
        }

        $age = time() - (int) $m[2];

        return $age >= ($refreshAfter ?? self::REFRESH_AFTER)
            ? self::mint($subject)
            : null;
    }

    public static function mint(string $subject, ?int $at = null): string
    {
        $ts = $at ?? time();
        $payload = $subject.'.'.$ts;

        return $payload.'.'.hash_hmac('sha256', $payload, self::secret());
    }

    /**
     * A read-only, short-lived token for an admin previewing the customer's personal area.
     * Signed over a DIFFERENT payload (the `pv.` prefix is part of it), so a preview token
     * can never be replayed as a full one.
     */
    public static function mintPreview(string $subject, ?int $at = null): string
    {
        $ts = $at ?? time();
        $payload = self::PREVIEW_PREFIX.'.'.$subject.'.'.$ts;

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

        if (! hash_equals(hash_hmac('sha256', $subject.'.'.$ts, $secret), $sig)) {
            return null;
        }

        return self::withinAge((int) $ts, $maxAge ?? (int) config('shopify.storefront_token_max_age', 86400))
            ? $subject
            : null;
    }

    /** Verify a preview token. Returns the subject, or null if it is not a valid preview. */
    public static function verifyPreview(string $token): ?string
    {
        $secret = self::secret();
        if ($secret === '') {
            return null;
        }

        if (preg_match(self::PREVIEW_SHAPE, trim($token), $m) !== 1) {
            return null;
        }

        [, $subject, $ts, $sig] = $m;

        $payload = self::PREVIEW_PREFIX.'.'.$subject.'.'.$ts;
        if (! hash_equals(hash_hmac('sha256', $payload, $secret), $sig)) {
            return null;
        }

        return self::withinAge((int) $ts, self::PREVIEW_MAX_AGE) ? $subject : null;
    }

    public static function isPreview(string $token): bool
    {
        return str_starts_with(trim($token), self::PREVIEW_PREFIX.'.');
    }

    private static function withinAge(int $issuedAt, int $maxAge): bool
    {
        $age = time() - $issuedAt;

        return $age >= -self::SKEW && $age <= $maxAge;
    }

    private static function secret(): string
    {
        return (string) config('shopify.storefront_token_secret', '');
    }
}
