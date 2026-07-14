<?php

namespace Tests\Feature;

use App\Filament\Forms\AllergySelect;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Modules\MillsSubscriptions\Services\Recommendation\DogFoodRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sensitivities are chosen from the catalog, never typed.
 *
 * A sensitivity only does anything if it matches a class the product actually carries — the
 * recommender excludes a food by comparing the dog's allergy list against the product's type
 * and tags. Free text let an admin record "chicken" in English, or "עוף " with a trailing
 * space, and exclude precisely nothing while looking, on screen, exactly like a sensitivity
 * that works.
 */
class AllergyPickerTest extends TestCase
{
    use RefreshDatabase;

    private DogFoodRecommender $recommender;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recommender = new DogFoodRecommender;
    }

    private function food(string $title, array $tags, int $grams = 100, ?string $type = null): Product
    {
        $product = Product::query()->create([
            'shopify_product_id' => (string) random_int(1000, 99999),
            'title' => $title,
            'status' => 'active',
            'multiplier' => 1.0,
            'collections' => ['כלבים'],
            'product_type' => $type,
            'tags' => $tags,
        ]);

        ProductVariant::query()->create([
            'shopify_variant_id' => (string) random_int(100000, 999999),
            'product_id' => $product->id,
            'title' => "{$grams}g",
            'sku' => "AA30 - אריזה יומית של {$grams} גרם",
            'grams' => $grams,
            'pack_size' => 30,
            'available' => true,
        ]);

        return $product->refresh();
    }

    private function dog(array $attributes = []): Dog
    {
        $customer = Customer::query()->create(['email' => 'a'.uniqid().'@example.com']);

        return Dog::query()->create(array_merge([
            'customer_id' => $customer->id,
            'name' => 'Rex',
            'status' => 'active',
            'weight' => 10,
            'age' => 3,
            'activity' => 1,
            'body' => 1,
            'neutered' => true,
        ], $attributes));
    }

    public function test_the_options_are_the_flavours_the_catalog_actually_carries(): void
    {
        $this->food('Chicken', ['עוף']);
        $this->food('Fish', ['דגים']);

        $options = $this->recommender->allergenOptions($this->dog());

        $this->assertSame(['דגים', 'עוף'], array_keys($options));
    }

    public function test_the_engines_own_size_rules_are_not_offered_as_sensitivities(): void
    {
        // "micro-kibble" and "super-food" are how the engine sizes a portion, not something a
        // dog reacts to. Offering them would let an admin quietly disable the size rules.
        $this->food('Super', ['סופר פוד', 'עוף']);

        $options = $this->recommender->allergenOptions($this->dog());

        $this->assertSame(['עוף'], array_keys($options));
    }

    public function test_a_flavour_this_dog_could_never_eat_is_not_offered(): void
    {
        // Micro-kibble is for dogs of 4 kg and under. A 20 kg dog is never offered it, so
        // "duck", which only exists as micro-kibble, would exclude nothing for this dog —
        // and an admin who picked it would believe they had ruled something out.
        $this->food('Chicken', ['עוף']);
        $this->food('Duck micro', ['ברווז', 'מיקרו גרגיר']);

        $options = $this->recommender->allergenOptions($this->dog(['weight' => 20]));

        $this->assertSame(['עוף'], array_keys($options));
        $this->assertArrayNotHasKey('ברווז', $options);
    }

    public function test_a_recorded_sensitivity_is_never_silently_dropped_from_the_list(): void
    {
        $this->food('Chicken', ['עוף']);

        // The turkey product was delisted after the sensitivity was recorded. If the option
        // disappeared, opening the form and saving would quietly clear the allergy — and the
        // dog would be offered exactly the food it reacts to.
        $dog = $this->dog(['allergies' => 'הודו']);

        $this->assertArrayHasKey('הודו', $this->recommender->allergenOptions($dog));
    }

    public function test_choosing_a_sensitivity_actually_removes_the_food(): void
    {
        $this->food('Chicken', ['עוף']);
        $this->food('Fish', ['דגים']);

        $healthy = $this->dog();
        $allergic = $this->dog(['allergies' => 'עוף']);

        $this->assertSame(2, $this->recommender->eligibleProducts($healthy)->count());

        $left = $this->recommender->eligibleProducts($allergic);
        $this->assertSame(1, $left->count());
        $this->assertSame('Fish', $left->first()->title);
    }

    public function test_a_product_type_counts_as_a_sensitivity_just_as_a_tag_does(): void
    {
        // The theme's hasTClass matches the product TYPE or any tag. The picker must offer
        // both, or a whole product type would be unfilterable.
        $this->food('Venison', [], 100, 'צבי');

        $this->assertArrayHasKey('צבי', $this->recommender->allergenOptions($this->dog()));
    }

    // --- the string the database actually stores ------------------------------

    public function test_the_picker_stores_the_same_comma_separated_string_the_storefront_sends(): void
    {
        // The column is a string: the storefront writes one, the v1 import wrote one, and the
        // recommender splits one. The picker changes how it is chosen, not what it is.
        $this->assertSame('עוף, דגים', AllergySelect::join(['עוף', 'דגים']));
        $this->assertSame(['עוף', 'דגים'], AllergySelect::split('עוף, דגים'));
    }

    public function test_no_sensitivities_is_stored_as_null_not_an_empty_string(): void
    {
        $this->assertNull(AllergySelect::join([]));
        $this->assertNull(AllergySelect::join(''));
        $this->assertSame([], AllergySelect::split(null));
    }

    public function test_a_string_written_by_the_storefront_opens_correctly_in_the_picker(): void
    {
        // Round trip: what v1 stored must select the right options, not appear as one long
        // unmatched value.
        $this->assertSame(['עוף', 'דגים'], AllergySelect::split('עוף,דגים'));
        $this->assertSame(['עוף', 'דגים'], AllergySelect::split(' עוף ; דגים '));
    }

    public function test_the_recommender_reads_the_pickers_array_as_well_as_the_stored_string(): void
    {
        $this->food('Chicken', ['עוף']);
        $this->food('Fish', ['דגים']);

        // The live form hands the recommender an array (nothing has been saved yet), so the
        // product list re-filters as soon as a sensitivity is picked.
        $fromForm = new Dog(['weight' => 10, 'age' => 3, 'activity' => 1, 'body' => 1, 'neutered' => true, 'allergies' => ['עוף']]);

        $left = $this->recommender->eligibleProducts($fromForm);

        $this->assertSame(1, $left->count());
        $this->assertSame('Fish', $left->first()->title);
    }
}
