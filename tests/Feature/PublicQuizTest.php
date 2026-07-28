<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\QuizDog;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The dog quiz is filled in by someone who has no account — that is the whole point of it.
 *
 * The theme used to post the finished quiz to the legacy /shopify/dog/save-quiz-dog, which
 * sits behind the server-to-server api.secret. From a browser that answers 401, so on the
 * live site the quiz silently stopped saving anything. The public storefront endpoint is
 * the fix, and these tests pin the two halves that matter: an anonymous visitor can save,
 * and the saved quiz is inert until a logged-in customer claims it.
 */
class PublicQuizTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.storefront_token_secret' => 'quiz-test-secret']);
    }

    public function test_a_visitor_with_no_account_can_save_a_quiz(): void
    {
        $response = $this->postJson('/storefront/quiz-dogs', [
            'name' => 'Rexi',
            'weight' => 12,
            'variants' => ['gid://shopify/ProductVariant/111'],
        ])->assertOk()->assertJsonPath('ok', true);

        $id = $response->json('data.quizDog.id');
        $this->assertNotEmpty($id);

        $quizDog = QuizDog::query()->where('public_id', $id)->firstOrFail();
        $this->assertNull($quizDog->customer_id, 'nobody owns a quiz taken by a stranger');
        $this->assertSame('Rexi', $quizDog->payload['name']);
    }

    public function test_an_empty_quiz_is_refused(): void
    {
        $this->postJson('/storefront/quiz-dogs', [])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
    }

    /** Saving is public; turning the quiz into a real dog is not. */
    public function test_the_saved_quiz_cannot_be_linked_without_logging_in(): void
    {
        $id = $this->postJson('/storefront/quiz-dogs', ['name' => 'Rexi'])->json('data.quizDog.id');

        $this->postJson("/storefront/me/quiz-dogs/{$id}/link", [
            'variants' => ['gid://shopify/ProductVariant/111'],
        ])->assertStatus(401);

        $this->assertSame(0, Dog::query()->count());
    }

    public function test_a_quiz_taken_before_signing_up_becomes_a_dog_after_logging_in(): void
    {
        // The real sequence: quiz first, account second.
        $id = $this->postJson('/storefront/quiz-dogs', [
            'name' => 'Rexi',
            'variants' => ['gid://shopify/ProductVariant/111'],
        ])->json('data.quizDog.id');

        $customer = Customer::query()->create([
            'email' => 'later@example.com',
            'shopify_customer_id' => '778899',
        ]);

        $token = StorefrontToken::mint((string) $customer->shopify_customer_id);

        $this->postJson("/storefront/me/quiz-dogs/{$id}/link", [
            'variants' => ['gid://shopify/ProductVariant/111'],
        ], ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, Dog::query()->where('customer_id', $customer->id)->count());
    }

    public function test_the_public_endpoint_is_rate_limited(): void
    {
        // It is public and it writes a row, so it must not be an open faucet.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/storefront/quiz-dogs', ['name' => 'Rexi'.$i])->assertOk();
        }

        $this->postJson('/storefront/quiz-dogs', ['name' => 'one too many'])->assertStatus(429);
    }
}
