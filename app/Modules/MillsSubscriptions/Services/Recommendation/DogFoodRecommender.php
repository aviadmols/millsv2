<?php

namespace App\Modules\MillsSubscriptions\Services\Recommendation;

use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;

/**
 * Weight → calories → daily grams → variant.
 *
 * This is a faithful port of the engine that has always lived in the Shopify
 * theme (THEME/assets/quiz-engine.js computeCalories, quiz-api.js
 * findBestVariantsForProduct + productPassesQuizFilters). The theme still runs its
 * own copy; this one backs the admin and the /api/dogs/recommend endpoint. They
 * MUST agree, so every constant and every quirk below is transcribed verbatim —
 * where the original is odd, the oddity is preserved and labelled, not "fixed".
 *
 * Deliberately NOT ported: v1's admin-side recommender
 * (SubscriptionViewPage::recommendVariantsForDog), which used a bare 70·w^0.75 with
 * no multipliers and a hardcoded 4 kcal/g. It disagreed with the storefront — that
 * is the bug this class exists to end.
 */
class DogFoodRecommender
{
    // === Calorie multipliers (quiz-engine.js SCORES) ===
    private const AGE_YOUNG = 1.0;

    private const AGE_SENIOR = 1.2;          // age > 9

    private const CASTRATION_NEUTERED = 1.0;

    private const CASTRATION_INTACT = 1.11;  // an intact dog burns ~11% more

    private const ACTIVITY = [0 => 1.2, 1 => 1.25, 2 => 1.4];   // inactive | active | very active

    private const BODY = [0 => 1.1, 1 => 1.0, 2 => 0.9];        // thin | normal | heavy

    // === Guards (quiz-engine.js) ===
    public const CALORIES_NO_REC_MAX = 2372;   // above this the dog needs a bespoke plan

    public const WEIGHT_MIN = 1;

    public const WEIGHT_MAX = 86;

    public const AGE_MIN = 1;

    public const AGE_MAX = 14;

    // === Eligibility classes (matched against product type OR any tag) ===
    private const CLASS_MICRO = 'מיקרו-גרגיר';

    private const CLASS_SUPER_FOOD = 'סופר-פוד';

    private const CLASS_SENIOR = 'מבוגרים';

    private const CLASS_DOGS_COLLECTION = 'כלבים';

    /** Never recommended as a recurring food, whatever the dog. */
    private const ALWAYS_EXCLUDED = ['גורים', 'טעימות', 'חטיפים', 'טיפוח', 'אביזרים'];

    /**
     * Recommended daily kcal.
     *
     * Two quirks are load-bearing and intentional:
     *  - a senior (age > 9) is scored on age ALONE — activity, body and neutering
     *    are ignored entirely;
     *  - an inactive dog skips the neutering factor.
     */
    public function calories(Dog $dog): int
    {
        $weight = (float) ($dog->weight ?? 0);
        $age = (float) ($dog->age ?? 0);

        $rer = 70 * ($weight ** 0.75);

        if ($age > 9) {
            return (int) round($rer * self::AGE_SENIOR);
        }

        $activity = self::ACTIVITY[(int) ($dog->activity ?? 2)] ?? self::ACTIVITY[2];
        $body = self::BODY[(int) ($dog->body ?? 2)] ?? self::BODY[2];

        // Unknown neutering is treated as INTACT — the theme's own fallback.
        $castration = $dog->neutered === true ? self::CASTRATION_NEUTERED : self::CASTRATION_INTACT;

        $calories = (int) ($dog->activity ?? 2) === 0
            ? $rer * self::AGE_YOUNG * $activity * $body
            : $rer * self::AGE_YOUNG * $castration * $activity * $body;

        return (int) round($calories);
    }

    /**
     * Daily grams of THIS food. The pivot is the product's energy density
     * (kcal/gram), held in Shopify as the `product.multiplier` metafield — the same
     * calorie requirement maps to different gram amounts for different foods.
     */
    public function gramsBenchmark(int $calories, Product $product): int
    {
        $multiplier = (float) ($product->multiplier ?: 1);
        if ($multiplier <= 0) {
            $multiplier = 1;
        }

        return (int) ceil($calories / $multiplier);
    }

    /** Is the dog inside the range the engine is willing to advise on? */
    public function canRecommend(Dog $dog): bool
    {
        $weight = (float) ($dog->weight ?? 0);
        $age = (float) ($dog->age ?? 0);

        return $weight >= self::WEIGHT_MIN
            && $weight <= self::WEIGHT_MAX
            && $age >= self::AGE_MIN
            && $age <= self::AGE_MAX
            && $this->calories($dog) <= self::CALORIES_NO_REC_MAX;
    }

    /**
     * The foods this dog may be fed at all.
     *
     * @return Collection<int, Product>
     */
    public function eligibleProducts(Dog $dog): Collection
    {
        $weight = (float) ($dog->weight ?? 0);
        $age = (float) ($dog->age ?? 0);
        $allergies = $this->allergyList($dog);

        return Product::query()
            ->with('variants')
            ->where('status', 'active')
            ->get()
            ->filter(function (Product $product) use ($weight, $age, $allergies) {
                if (! $this->inDogsCollection($product)) {
                    return false;
                }

                foreach (self::ALWAYS_EXCLUDED as $class) {
                    if ($this->hasClass($product, $class)) {
                        return false;
                    }
                }

                // Size rules: tiny dogs get micro-kibble and nothing else; big dogs
                // never get it. Super-food is for 10 kg and up.
                $micro = $this->hasClass($product, self::CLASS_MICRO);
                if ($weight <= 4 && ! $micro) {
                    return false;
                }
                if ($weight > 7 && $micro) {
                    return false;
                }
                if ($weight < 10 && $this->hasClass($product, self::CLASS_SUPER_FOOD)) {
                    return false;
                }
                if ($age < 10 && $this->hasClass($product, self::CLASS_SENIOR)) {
                    return false;
                }

                foreach ($allergies as $allergy) {
                    if ($this->hasClass($product, $allergy)) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    /**
     * Pick this product's best variant for the dog.
     *
     * The rule that matters: ROUND UP. A variant at or above the requirement always
     * beats a closer one below it — underfeeding is never an acceptable rounding
     * error. We only drop below the benchmark when nothing at or above it can
     * actually be bought.
     *
     * `variant2` is the same grams/day in a different pack size (30 = one flavour,
     * 15 = two flavours), NOT a second flavour.
     *
     * @return array{variant: ProductVariant, variant2: ?ProductVariant}|null
     */
    public function bestVariants(Product $product, int $calories): ?array
    {
        $benchmark = $this->gramsBenchmark($calories, $product);

        $higher = [];
        $lower = [];

        foreach ($product->variants as $variant) {
            if ($variant->grams === null) {
                continue;   // not a portioned subscription variant
            }

            $entry = ['variant' => $variant, 'diff' => abs($variant->grams - $benchmark)];
            if ($variant->grams >= $benchmark) {
                $higher[] = $entry;
            } else {
                $lower[] = $entry;
            }
        }

        $sort = static fn (array &$list) => usort($list, static fn ($a, $b) => $a['diff'] <=> $b['diff']);
        $sort($higher);
        $sort($lower);

        $chosen = $this->firstBuyableTier($higher) ?? $this->firstBuyableTier($lower);
        if ($chosen === null) {
            return null;
        }

        [$first, $second] = $chosen;

        // Keep the runner-up only when it is the same portion in another pack size.
        $variant2 = ($second !== null && $second->available && $second->grams === $first->grams)
            ? $second
            : null;

        return ['variant' => $first, 'variant2' => $variant2];
    }

    /**
     * The full answer for a dog: what it needs, and the best variant of every food
     * it may eat, best fit first.
     *
     * @return array{
     *     can_recommend: bool,
     *     calories: int,
     *     products: list<array{product: Product, benchmark: int, variant: ProductVariant, variant2: ?ProductVariant}>
     * }
     */
    public function recommend(Dog $dog): array
    {
        $calories = $this->calories($dog);

        if (! $this->canRecommend($dog)) {
            return ['can_recommend' => false, 'calories' => $calories, 'products' => []];
        }

        $products = [];

        foreach ($this->eligibleProducts($dog) as $product) {
            $best = $this->bestVariants($product, $calories);
            if ($best === null) {
                continue;
            }

            $benchmark = $this->gramsBenchmark($calories, $product);

            $products[] = [
                'product' => $product,
                'benchmark' => $benchmark,
                'variant' => $best['variant'],
                'variant2' => $best['variant2'],
            ];
        }

        // Closest fit first — the admin sees the best match at the top.
        usort($products, static fn ($a, $b) => abs($a['variant']->grams - $a['benchmark'])
            <=> abs($b['variant']->grams - $b['benchmark']));

        return ['can_recommend' => true, 'calories' => $calories, 'products' => $products];
    }

    // --- internals -----------------------------------------------------------

    /**
     * Take a tier if either of its top two is buyable, and hand back that pair.
     *
     * @param  list<array{variant: ProductVariant, diff: int}>  $tier
     * @return array{0: ProductVariant, 1: ?ProductVariant}|null
     */
    private function firstBuyableTier(array $tier): ?array
    {
        if ($tier === []) {
            return null;
        }

        $first = $tier[0]['variant'];
        $second = $tier[1]['variant'] ?? null;

        $buyable = $first->available || ($second !== null && $second->available);

        return $buyable ? [$first, $second] : null;
    }

    /** Matches the theme's hasTClass: the product TYPE or any tag, whitespace-normalised. */
    private function hasClass(Product $product, string $class): bool
    {
        $want = $this->normalise($class);
        if ($want === '') {
            return false;
        }

        if ($this->normalise((string) $product->product_type) === $want) {
            return true;
        }

        foreach ((array) ($product->tags ?? []) as $tag) {
            if ($this->normalise((string) $tag) === $want) {
                return true;
            }
        }

        return false;
    }

    private function inDogsCollection(Product $product): bool
    {
        $collections = (array) ($product->collections ?? []);

        // A store that has never synced collections must not silently filter the
        // whole catalog away — fall back to letting the product through.
        if ($collections === []) {
            return true;
        }

        foreach ($collections as $title) {
            if ($this->normalise((string) $title) === $this->normalise(self::CLASS_DOGS_COLLECTION)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function allergyList(Dog $dog): array
    {
        $raw = trim((string) ($dog->allergies ?? ''));
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $raw) ?: [])));
    }

    /** quiz-api.js normalizeClassSegment: collapse whitespace to dashes, trim. */
    private function normalise(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', '-', trim($value)));
    }
}
