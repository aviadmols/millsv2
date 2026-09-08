<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\AppSetting;

/**
 * Whether a recurring order pays for delivery, and how much.
 *
 * One rule, set in Settings: orders whose products come to LESS than a threshold pay a
 * flat delivery fee; everything at or above it ships free. Compared AFTER the subscriber
 * discount — that is the figure the customer is actually paying for the food, and the
 * only one a free-shipping threshold can honestly be measured against.
 *
 * Off by default (no fee), so nothing changes for anybody until a fee is deliberately set.
 * The fee is decided in exactly one place and applied in three — the upcoming draft, the
 * charge preview, and the paid order — because a shipping line that appears on the draft
 * but not on the paid order leaves the order underpaid by exactly that fee.
 */
final class ShippingPolicy
{
    public const SETTING_FEE = 'shipping_fee';

    public const SETTING_THRESHOLD = 'free_shipping_threshold';

    public const SETTING_TITLE = 'shipping_title';

    /** @return array{fee: float, threshold: float, title: string} */
    public static function settings(): array
    {
        return [
            'fee' => round(max(0.0, (float) AppSetting::get(self::SETTING_FEE, '0')), 2),
            'threshold' => round(max(0.0, (float) AppSetting::get(self::SETTING_THRESHOLD, '0')), 2),
            'title' => trim((string) AppSetting::get(self::SETTING_TITLE, '')) ?: __('subscriptions.shipping_title'),
        ];
    }

    public static function enabled(): bool
    {
        $s = self::settings();

        return $s['fee'] > 0 && $s['threshold'] > 0;
    }

    /**
     * The delivery line for an order whose products (after discount) come to $productTotal,
     * or null when delivery is free.
     *
     * @return array{title: string, price: float}|null
     */
    public static function feeFor(float $productTotal): ?array
    {
        $s = self::settings();

        if ($s['fee'] <= 0 || $s['threshold'] <= 0) {
            return null;
        }

        // Strictly below: an order that reaches the threshold exactly has earned free
        // delivery, and "spend ₪200 for free shipping" must be true at ₪200.00.
        if (round($productTotal, 2) >= $s['threshold']) {
            return null;
        }

        return ['title' => $s['title'], 'price' => $s['fee']];
    }
}
