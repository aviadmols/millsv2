<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Modules\MillsSubscriptions\Services\Recommendation\DogFoodRecommender;
use App\Modules\MillsSubscriptions\Services\Shopify\ProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pins the recommender to the engine that runs in the Shopify theme
 * (quiz-engine.js / quiz-api.js). If the server and the theme ever disagree about
 * a dog's portion, a customer is under- or over-fed — so the formula, its two
 * quirks, and the never-underfeed ranking are all asserted against hand-computed
 * values taken straight from the theme's constants.
 */
class DogFoodRecommenderTest extends TestCase
{
    use RefreshDatabase;

    private DogFoodRecommender $recommender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recommender = new DogFoodRecommender;
    }

    private function dog(array $attributes = []): Dog
    {
        $customer = Customer::query()->create(['email' => 'rec'.uniqid().'@example.com']);

        return Dog::query()->create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'Test',
            'status' => 'active',
            'weight' => 10,
            'age' => 3,
            'activity' => 1,     // active
            'body' => 1,         // normal
            'neutered' => true,
        ], $attributes));
    }

    // --- the calorie formula -------------------------------------------------

    public function test_calories_follow_the_rer_formula_with_the_theme_multipliers(): void
    {
        // RER = 70 × 10^0.75 = 393.60 ; × age 1 × castration 1 × activity 1.25 × body 1.0
        $dog = $this->dog(['weight' => 10, 'age' => 3, 'activity' => 1, 'body' => 1, 'neutered' => true]);

        $this->assertSame((int) round(70 * (10 ** 0.75) * 1.25), $this->recommender->calories($dog));
        $this->assertSame(492, $this->recommender->calories($dog));
    }

    public function test_an_intact_dog_burns_eleven_percent_more(): void
    {
        $neutered = $this->dog(['neutered' => true]);
        $intact = $this->dog(['neutered' => false]);

        $this->assertSame(492, $this->recommender->calories($neutered));
        $this->assertSame((int) round(492 * 1.11), $this->recommender->calories($intact));
    }

    public function test_unknown_neutering_is_treated_as_intact_like_the_theme_does(): void
    {
        $unknown = $this->dog(['neutered' => null]);
        $intact = $this->dog(['neutered' => false]);

        $this->assertSame(
            $this->recommender->calories($intact),
            $this->recommender->calories($unknown),
        );
    }

    /** QUIRK 1: a senior is scored on age alone — activity, body and neutering are ignored. */
    public function test_a_senior_ignores_activity_body_and_neutering(): void
    {
        $lazyFatIntactSenior = $this->dog([
            'age' => 12, 'activity' => 0, 'body' => 2, 'neutered' => false,
        ]);
        $athleticThinNeuteredSenior = $this->dog([
            'age' => 12, 'activity' => 2, 'body' => 0, 'neutered' => true,
        ]);

        $expected = (int) round(70 * (10 ** 0.75) * 1.2);

        $this->assertSame($expected, $this->recommender->calories($lazyFatIntactSenior));
        $this->assertSame($expected, $this->recommender->calories($athleticThinNeuteredSenior));
    }

    /** QUIRK 2: an inactive dog skips the neutering factor entirely. */
    public function test_an_inactive_dog_skips_the_neutering_factor(): void
    {
        $inactiveIntact = $this->dog(['activity' => 0, 'neutered' => false]);
        $inactiveNeutered = $this->dog(['activity' => 0, 'neutered' => true]);

        // Same calories despite differing neuter status — 1.11 is never applied.
        $this->assertSame(
            $this->recommender->calories($inactiveNeutered),
            $this->recommender->calories($inactiveIntact),
        );
        $this->assertSame((int) round(70 * (10 ** 0.75) * 1.2 * 1.0), $this->recommender->calories($inactiveIntact));
    }

    public function test_a_dog_needing_more_than_the_cap_gets_no_recommendation(): void
    {
        $giant = $this->dog(['weight' => 80, 'age' => 3, 'activity' => 2, 'body' => 0, 'neutered' => false]);

        $this->assertGreaterThan(DogFoodRecommender::CALORIES_NO_REC_MAX, $this->recommender->calories($giant));
        $this->assertFalse($this->recommender->canRecommend($giant));
        $this->assertFalse($this->recommender->recommend($giant)['can_recommend']);
    }

    // --- grams benchmark -----------------------------------------------------

    public function test_the_grams_benchmark_divides_calories_by_the_products_energy_density(): void
    {
        $dense = Product::query()->create([
            'shopify_product_id' => '1', 'title' => 'Dense', 'multiplier' => 4.0, 'status' => 'active',
        ]);
        $plain = Product::query()->create([
            'shopify_product_id' => '2', 'title' => 'Plain', 'multiplier' => 1.0, 'status' => 'active',
        ]);

        // 492 kcal of a 4 kcal/g food is 123 g; of a 1 kcal/g food, 492 g.
        $this->assertSame(123, $this->recommender->gramsBenchmark(492, $dense));
        $this->assertSame(492, $this->recommender->gramsBenchmark(492, $plain));
    }

    // --- variant ranking -----------------------------------------------------

    private function productWithGrams(array $grams, array $unavailable = []): Product
    {
        $product = Product::query()->create([
            'shopify_product_id' => (string) random_int(1000, 99999),
            'title' => 'Food',
            'multiplier' => 1.0,
            'status' => 'active',
            'collections' => ['כלבים'],
        ]);

        foreach ($grams as $i => $g) {
            ProductVariant::query()->create([
                'shopify_variant_id' => (string) random_int(100000, 999999),
                'product_id' => $product->id,
                'title' => "{$g}g",
                'sku' => "AA30 - אריזה יומית של {$g} גרם",
                'grams' => $g,
                'pack_size' => 30,
                'available' => ! in_array($g, $unavailable, true),
                'position' => $i,
            ]);
        }

        return $product->refresh();
    }

    public function test_it_rounds_up_and_never_underfeeds(): void
    {
        // Benchmark 492. 500 is 8 away; 490 is only 2 away — but 490 underfeeds.
        $product = $this->productWithGrams([400, 490, 500, 600]);

        $best = $this->recommender->bestVariants($product, 492);

        $this->assertNotNull($best);
        $this->assertSame(500, $best['variant']->grams, 'must round UP even when a closer variant sits below');
    }

    public function test_it_drops_below_the_benchmark_only_when_nothing_above_it_is_buyable(): void
    {
        $product = $this->productWithGrams([400, 490, 500, 600], unavailable: [500, 600]);

        $best = $this->recommender->bestVariants($product, 492);

        $this->assertNotNull($best);
        $this->assertSame(490, $best['variant']->grams, 'falls back to the closest below when nothing above is in stock');
    }

    public function test_the_runner_up_is_the_same_portion_in_another_pack_not_another_size(): void
    {
        $product = $this->productWithGrams([500, 600]);

        // Add the 15-day pack of the SAME 500 g/day portion.
        ProductVariant::query()->create([
            'shopify_variant_id' => '777777',
            'product_id' => $product->id,
            'title' => '500g / 15',
            'sku' => 'AA15 - אריזה יומית של 500 גרם',
            'grams' => 500,
            'pack_size' => 15,
            'available' => true,
            'position' => 9,
        ]);

        $best = $this->recommender->bestVariants($product->refresh(), 492);

        $this->assertSame(500, $best['variant']->grams);
        $this->assertNotNull($best['variant2']);
        $this->assertSame(500, $best['variant2']->grams, 'variant2 is the same grams at a different pack size');
    }

    public function test_a_product_with_no_portioned_variants_is_not_recommendable(): void
    {
        $product = Product::query()->create([
            'shopify_product_id' => '9999', 'title' => 'Collar', 'multiplier' => 1, 'status' => 'active',
        ]);
        ProductVariant::query()->create([
            'shopify_variant_id' => '8888', 'product_id' => $product->id, 'sku' => 'COLLAR', 'grams' => null,
            'available' => true,
        ]);

        $this->assertNull($this->recommender->bestVariants($product->refresh(), 492));
    }

    // --- eligibility ---------------------------------------------------------

    private function food(string $title, array $tags = [], array $collections = ['כלבים']): Product
    {
        $product = Product::query()->create([
            'shopify_product_id' => (string) random_int(1000, 99999),
            'title' => $title,
            'tags' => $tags,
            'collections' => $collections,
            'multiplier' => 1.0,
            'status' => 'active',
        ]);

        ProductVariant::query()->create([
            'shopify_variant_id' => (string) random_int(100000, 999999),
            'product_id' => $product->id,
            'sku' => 'AA30 - אריזה יומית של 500 גרם',
            'grams' => 500,
            'pack_size' => 30,
            'available' => true,
        ]);

        return $product;
    }

    public function test_a_tiny_dog_gets_micro_kibble_and_nothing_else(): void
    {
        $this->food('Micro', ['מיקרו-גרגיר']);
        $this->food('Regular');

        $titles = $this->recommender->eligibleProducts($this->dog(['weight' => 3]))->pluck('title');

        $this->assertEquals(['Micro'], $titles->all());
    }

    public function test_a_big_dog_never_gets_micro_kibble(): void
    {
        $this->food('Micro', ['מיקרו-גרגיר']);
        $this->food('Regular');

        $titles = $this->recommender->eligibleProducts($this->dog(['weight' => 20]))->pluck('title');

        $this->assertEquals(['Regular'], $titles->all());
    }

    public function test_super_food_is_only_for_ten_kilos_and_up(): void
    {
        $this->food('Super', ['סופר-פוד']);
        $this->food('Regular');

        $this->assertEquals(['Regular'],
            $this->recommender->eligibleProducts($this->dog(['weight' => 8]))->pluck('title')->all());

        $this->assertEqualsCanonicalizing(['Super', 'Regular'],
            $this->recommender->eligibleProducts($this->dog(['weight' => 12]))->pluck('title')->all());
    }

    public function test_treats_puppy_food_and_accessories_are_never_recurring_food(): void
    {
        $this->food('Treats', ['חטיפים']);
        $this->food('Puppy', ['גורים']);
        $this->food('Collar', ['אביזרים']);
        $this->food('Regular');

        $this->assertEquals(['Regular'],
            $this->recommender->eligibleProducts($this->dog())->pluck('title')->all());
    }

    public function test_a_food_the_dog_is_allergic_to_is_excluded(): void
    {
        $this->food('Chicken', ['עוף']);
        $this->food('Salmon', ['סלמון']);

        $dog = $this->dog(['allergies' => 'עוף']);

        $this->assertEquals(['Salmon'],
            $this->recommender->eligibleProducts($dog)->pluck('title')->all());
    }

    public function test_the_product_type_counts_as_a_class_just_like_a_tag(): void
    {
        $product = $this->food('TypedTreat');
        $product->forceFill(['product_type' => 'חטיפים'])->save();
        $this->food('Regular');

        $this->assertEquals(['Regular'],
            $this->recommender->eligibleProducts($this->dog())->pluck('title')->all());
    }

    public function test_a_product_outside_the_dogs_collection_is_excluded(): void
    {
        $this->food('CatFood', [], ['חתולים']);
        $this->food('DogFood');

        $this->assertEquals(['DogFood'],
            $this->recommender->eligibleProducts($this->dog())->pluck('title')->all());
    }

    // --- the SKU parsers the whole thing rests on ----------------------------

    public function test_the_real_sku_format_is_parsed_correctly(): void
    {
        $sku = 'SF30 - אריזה יומית של 79 גרם';

        $this->assertSame(79, ProductSyncService::parseGrams($sku), 'daily grams');
        $this->assertSame(30, ProductSyncService::parsePackSize($sku), 'pack size in days');
        $this->assertSame('sf', ProductSyncService::parseFlavorKey($sku), 'flavour code without the pack size');

        // The 15-day pack of the same flavour and portion.
        $this->assertSame(15, ProductSyncService::parsePackSize('SF15 - אריזה יומית של 79 גרם'));
        $this->assertSame('sf', ProductSyncService::parseFlavorKey('SF15 - אריזה יומית של 79 גרם'));
    }

    public function test_pack_size_is_not_confused_by_grams_that_contain_15_or_30(): void
    {
        // The theme's substring match reads "30" out of "130". The anchored rule does not.
        $this->assertSame(15, ProductSyncService::parsePackSize('SF15 - אריזה יומית של 130 גרם'));
        $this->assertSame(130, ProductSyncService::parseGrams('SF15 - אריזה יומית של 130 גרם'));
    }

    public function test_a_non_portioned_sku_yields_no_grams(): void
    {
        $this->assertNull(ProductSyncService::parseGrams('COLLAR'));
        $this->assertNull(ProductSyncService::parsePackSize('COLLAR'));
    }
}
