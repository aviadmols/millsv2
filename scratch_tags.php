<?php

use App\Models\Product;

$tally = [];
foreach (Product::query()->where('status', 'active')->get() as $p) {
    if (! in_array('כלבים', (array) ($p->collections ?? []), true)) {
        continue;
    }
    foreach ((array) ($p->tags ?? []) as $t) {
        $t = trim((string) $t);
        if ($t !== '') {
            $tally[$t] = ($tally[$t] ?? 0) + 1;
        }
    }
}
arsort($tally);
foreach ($tally as $t => $n) {
    echo "$n\t$t\n";
}
echo "--- product_types ---\n";
foreach (Product::query()->where('status', 'active')->pluck('product_type')->unique()->filter() as $t) {
    echo "$t\n";
}
echo '--- dog products in collection: '.Product::query()->where('status', 'active')->count()."\n";
