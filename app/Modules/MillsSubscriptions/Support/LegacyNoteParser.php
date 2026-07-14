<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Support\ShopifyId;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * The legacy iCount subscription, as JSON in a Shopify customer's `note` field.
 *
 * These are the customers who never had a PayMe card. v1 kept their whole subscription — the
 * dogs, their flavours, the next delivery — in that one text field, and rendered a virtual
 * subscription from it. v2's import (`mills:import-from-v1`) took only the PayMe half of the
 * population and skipped them entirely, so in v2 they simply do not exist. This parser is how
 * they come back.
 *
 * The note is READ, never written. It is a one-way import: once a customer is in the database
 * the note is dead to us.
 *
 * Ported from v1's `LegacyNoteParser`. Its quirks are load-bearing and preserved deliberately;
 * two things are deliberately NOT preserved, and both are marked below.
 *
 * Real note:
 * {"discount":0.9,"interval":1,"status":"account-active","dogs":[{"status":"active",
 *  "quizData":{"allergy":[],"age":8,"weight":3,"activity":0,"body":1},"name":"כלב 1",
 *  "sex":0,"avatar":1,"caloriesPerDay":191,"variants":[{"id":39357390782621,
 *  "handle":"...","name":"...","grams":1530,"price":171}]}],"nextDelivery":"2026-06-18"}
 */
class LegacyNoteParser
{
    /** @return array<string, mixed>|null */
    public static function decode(string $note): ?array
    {
        $note = trim($note);

        if ($note === '') {
            return null;
        }

        try {
            $decoded = json_decode($note, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * An ACTIVE legacy account, normalised into v2's own vocabulary — or null when the note is
     * missing, unparseable, not active, or carries no dog worth shipping to.
     *
     * @return array{frequency_months: int, next_charge_at: ?string, start_date: ?string,
     *               double_food: bool, discount_percent: ?float, dogs: list<array<string, mixed>>}|null
     */
    public static function parseActiveNote(string $note): ?array
    {
        $legacy = self::decode($note);

        if ($legacy === null) {
            return null;
        }

        // iCount historically wrote "account-active"; later exports write plain "active".
        // Both are the same thing, and rejecting the second would silently drop real customers.
        $status = strtolower(trim((string) ($legacy['status'] ?? '')));

        if ($status !== 'account-active' && $status !== 'active') {
            return null;
        }

        $dogs = self::parseDogs($legacy);

        if ($dogs === []) {
            return null;
        }

        return [
            // DEVIATION 1: v1 emitted 'Monthly' / '2 Months' — metaobject vocabulary. v2's
            // column is an integer number of months.
            'frequency_months' => ((int) ($legacy['interval'] ?? 1)) === 2 ? 2 : 1,
            'next_charge_at' => self::normalizeDate((string) ($legacy['nextDelivery'] ?? '')),
            'start_date' => self::normalizeDate((string) ($legacy['startDate'] ?? '')),
            'double_food' => (bool) ($legacy['doubleFood'] ?? false),
            // DEVIATION 2: v1 threw `discount` away. v2 BILLS on it — DraftOrderService puts
            // this number on the order — so dropping it here is not cosmetic.
            'discount_percent' => self::discountPercent($legacy['discount'] ?? null),
            'dogs' => $dogs,
        ];
    }

    /**
     * The note's `discount` is a MULTIPLIER: 0.9 means "pay 90%", i.e. 10% off.
     *
     * Import it as the raw 0.9 and every order is discounted by 90%. Ignore it and a customer
     * on 0.85 silently gets the 10% default — a 5% overcharge on every order they ever
     * receive, for as long as the subscription lives.
     */
    private static function discountPercent(mixed $raw): ?float
    {
        if (! is_numeric($raw)) {
            return null;
        }

        $multiplier = (float) $raw;

        if ($multiplier <= 0 || $multiplier > 1) {
            return null;   // not a multiplier — do not guess, leave the DB default standing
        }

        return round((1 - $multiplier) * 100, 2);
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return list<array<string, mixed>>
     */
    private static function parseDogs(array $legacy): array
    {
        $rawDogs = $legacy['dogs'] ?? null;

        if (! is_array($rawDogs)) {
            return [];
        }

        $dogs = [];

        foreach ($rawDogs as $row) {
            if (! is_array($row)) {
                continue;
            }

            $dogStatus = strtolower(trim((string) ($row['status'] ?? '')));

            if ($dogStatus !== '' && $dogStatus !== 'active') {
                continue;
            }

            $variants = self::parseVariants($row['variants'] ?? null);

            // A dog with no variants has nothing to ship. Importing it would create a
            // subscription whose order is empty.
            if ($variants === []) {
                continue;
            }

            $quiz = is_array($row['quizData'] ?? null) ? $row['quizData'] : [];
            $allergy = $quiz['allergy'] ?? null;

            $dogs[] = [
                'name' => (string) ($row['name'] ?? ''),
                'sex' => $row['sex'] ?? null,
                'avatar' => $row['avatar'] ?? null,
                'calories_per_day' => $row['caloriesPerDay'] ?? null,
                'age' => $quiz['age'] ?? null,
                'weight' => $quiz['weight'] ?? null,
                'activity' => $quiz['activity'] ?? null,
                'body' => $quiz['body'] ?? null,
                // The comma-separated string the recommender and the allergy picker both read.
                'allergies' => is_array($allergy)
                    ? implode(', ', array_map('strval', $allergy))
                    : (string) ($allergy ?? ''),
                'variants' => $variants,
            ];
        }

        return $dogs;
    }

    /** @return list<string> ProductVariant GIDs */
    private static function parseVariants(mixed $rawVariants): array
    {
        if (! is_array($rawVariants)) {
            return [];
        }

        $variants = [];

        foreach ($rawVariants as $variant) {
            $id = is_array($variant)
                ? trim((string) ($variant['id'] ?? ''))
                : trim((string) $variant);

            if ($id === '') {
                continue;
            }

            $variants[] = ShopifyId::gid($id, 'ProductVariant');
        }

        return $variants;
    }

    /** null, not '' — an absent date is an absence, and the importer must be able to see it. */
    private static function normalizeDate(string $date): ?string
    {
        $date = trim($date);

        if ($date === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
