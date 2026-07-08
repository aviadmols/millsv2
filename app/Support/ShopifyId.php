<?php

namespace App\Support;

/**
 * Shopify GID helpers. Subscriptions/dogs/products reference Shopify objects by
 * their numeric id; the Admin API returns GIDs (gid://shopify/Product/123). Both
 * forms are accepted across the frozen contract.
 */
final class ShopifyId
{
    /** Extract the trailing numeric id from a GID (or return the input if already numeric). */
    public static function numeric(string $idOrGid): string
    {
        $idOrGid = trim($idOrGid);
        if ($idOrGid === '') {
            return '';
        }

        if (preg_match('/(\d+)(?:\?.*)?$/', $idOrGid, $m) === 1) {
            return $m[1];
        }

        return ctype_digit($idOrGid) ? $idOrGid : '';
    }

    /** Build a GID from a numeric id and a resource type. */
    public static function gid(string $numericOrGid, string $type): string
    {
        if (str_starts_with($numericOrGid, 'gid://')) {
            return $numericOrGid;
        }

        return "gid://shopify/{$type}/".self::numeric($numericOrGid);
    }
}
