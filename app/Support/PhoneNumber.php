<?php

namespace App\Support;

/**
 * Israeli phone numbers arrive in every shape a human or an integration can invent:
 *
 *     050-123-4567   0501234567   +972501234567   972-50-1234567   (050) 1234567
 *
 * They are all the same person. Matching a login attempt against a stored number by
 * string equality — which is what the OTP lookup used to do — finds nothing almost
 * every time.
 *
 * We reduce every number to the digits that actually identify the line: the last 9
 * (`501234567`). That is exactly the part shared by the local form and the
 * international one, so all the spellings above collapse to one key.
 */
final class PhoneNumber
{
    /** How many trailing digits identify an Israeli subscriber line. */
    private const SIGNIFICANT_DIGITS = 9;

    /**
     * The comparable key for a phone number, or null when there is nothing usable.
     */
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (strlen($digits) < self::SIGNIFICANT_DIGITS) {
            return null;
        }

        return substr($digits, -self::SIGNIFICANT_DIGITS);
    }

    /**
     * E.164 for sending (+972501234567). 019 accepts the local form too, but a
     * canonical value keeps the logs unambiguous.
     */
    public static function e164(?string $phone, string $countryCode = '972'): ?string
    {
        $key = self::normalise($phone);

        return $key === null ? null : '+'.$countryCode.$key;
    }

    /** The local dialling form (0501234567) — what 019 expects on the wire. */
    public static function local(?string $phone): ?string
    {
        $key = self::normalise($phone);

        return $key === null ? null : '0'.$key;
    }

    public static function looksLikeMobile(?string $phone): bool
    {
        $key = self::normalise($phone);

        // Israeli mobile prefixes are 05x → the key starts with 5.
        return $key !== null && str_starts_with($key, '5');
    }
}
