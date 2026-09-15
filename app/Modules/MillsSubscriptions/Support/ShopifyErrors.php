<?php

namespace App\Modules\MillsSubscriptions\Support;

/**
 * Shopify's refusal, in words a person can act on.
 *
 * Both of Shopify's APIs say no in their own shape. GraphQL returns `userErrors`, a list of
 * {field, message}; REST returns `errors`, which is a map of field ⇒ messages, or a bare
 * string, depending on the endpoint. Either way, the words that explain the refusal were
 * being written to the database and replaced on screen by a code — "shopify_draft_order_failed"
 * — so the admin looking at a failed order (subscription 324) was told THAT it failed and
 * never why, while the answer sat one table away.
 *
 * This keeps Shopify's own words, including which field it objected to, because "country
 * is not valid" is a thing an admin can fix and "failed" is not.
 */
final class ShopifyErrors
{
    public static function describe(mixed $errors): string
    {
        $parts = [];

        if (is_string($errors)) {
            $parts[] = trim($errors);
        } elseif (is_array($errors)) {
            foreach ($errors as $key => $error) {
                $parts[] = self::one($key, $error);
            }
        }

        $parts = array_values(array_filter(array_unique($parts)));

        return $parts === [] ? __('subscriptions.shopify_refused_unknown') : implode(' · ', $parts);
    }

    private static function one(int|string $key, mixed $error): string
    {
        // GraphQL userError: ['field' => ['input', 'shippingLine'], 'message' => '...']
        if (is_array($error) && array_key_exists('message', $error)) {
            $field = self::field($error['field'] ?? null);

            return trim(($field !== '' ? $field.': ' : '').(string) $error['message']);
        }

        // REST: ['shipping_address' => ['country is not valid']]
        if (is_array($error)) {
            $messages = implode(', ', array_map(fn ($m) => is_scalar($m) ? (string) $m : json_encode($m), $error));

            return trim((is_string($key) ? $key.': ' : '').$messages);
        }

        return trim((is_string($key) ? $key.': ' : '').(string) $error);
    }

    /** ['input', 'shippingLine', 'price'] reads as "shippingLine.price" — the input wrapper says nothing. */
    private static function field(mixed $field): string
    {
        if (! is_array($field)) {
            return is_scalar($field) ? (string) $field : '';
        }

        $path = array_values(array_filter($field, fn ($f) => $f !== 'input' && is_scalar($f)));

        return implode('.', $path);
    }
}
