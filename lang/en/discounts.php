<?php

return [
    'title' => 'Discount rules',
    'singular' => 'Discount rule',

    'section_what' => 'The discount',
    'section_what_help' => 'How much comes off, and off what. The name is printed on the customer\'s Shopify order.',
    'name' => 'Rule name',
    'name_help' => 'The customer reads this on their order — e.g. "Subscriber discount" or "Premium offer".',
    'percent' => 'Discount percentage',

    'scope' => 'What the discount comes off',
    'scope_order' => 'The whole order',
    'scope_order_help' => 'One percentage off the order total.',
    'scope_matching' => 'Only the products that matched',
    'scope_matching_help' => 'Only the lines meeting the product conditions below are discounted; the rest stay at full price. With no product conditions set, it applies to the whole order.',

    'is_active' => 'Active',
    'is_active_help' => 'An inactive rule affects no charge. This is how an offer is switched off without deleting it.',

    'section_when' => 'When the rule applies',
    'section_when_help' => 'Every field you fill in narrows the rule. A field left empty is simply not a condition — a rule with no conditions applies to every subscription.',
    'any' => 'Any',
    'any_help' => 'Empty = no condition.',

    'frequency' => 'Subscription frequency',
    'pack_sizes' => 'Pack size',
    'pack_15' => '15-day pack',
    'pack_30' => '30-day pack',
    'tags' => 'Product tags',
    'tags_help' => 'Tags from the product in Shopify. A new product given the tag joins the rule automatically.',
    'products' => 'Products',
    'variants' => 'Variants',
    'variants_help' => 'For single-variant precision. Search by title or SKU.',

    'priority' => 'Priority',
    'priority_help' => 'Only a tie-break: when two rules are worth the same, the higher one wins.',

    'matching_note' => 'When several rules match one subscription, the one that takes off the most MONEY wins — not the one with the highest percentage. A discount set by hand on the subscription screen outranks every rule.',

    'conditions' => 'Conditions',
    'applies_to_all' => 'Applies to every subscription',

    'empty' => 'No discount rules',
    'empty_help' => 'With no rules, the recurring cycle is billed at the store price. Add a rule to discount by product, tag, frequency or pack size.',
];
