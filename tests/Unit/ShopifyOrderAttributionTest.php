<?php

namespace Tests\Unit;

use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyOrderAttribution;
use Tests\TestCase;

/**
 * Proves CLAUDE.md law #11 / D17: every system order carries source_name ==
 * channel handle (what actually attributes to the app's Channel), plus the
 * tag + note_attributes fallback.
 */
class ShopifyOrderAttributionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shopify.order_source_name' => 'mills-subscriptions',
            'shopify.sales_channel_handle' => 'mills-subscriptions',
        ]);
    }

    public function test_apply_stamps_source_name_tag_and_note_attributes(): void
    {
        $order = ['line_items' => [['variant_id' => 1]], 'tags' => 'existing-tag'];

        $stamped = ShopifyOrderAttribution::apply($order, subscriptionId: 42);

        $this->assertSame('mills-subscriptions', $stamped['source_name']);
        $this->assertStringContainsString('existing-tag', $stamped['tags']);
        $this->assertStringContainsString('mills-subscriptions', $stamped['tags']);

        $names = array_column($stamped['note_attributes'], 'value', 'name');
        $this->assertSame('42', $names['mills_subscription_id']);
        $this->assertSame('recurring', $names['mills_order_role']);
    }

    public function test_source_name_equals_channel_handle(): void
    {
        // The invariant that makes the Channel column populate.
        $this->assertSame(ShopifyOrderAttribution::tag(), ShopifyOrderAttribution::sourceName());
    }
}
