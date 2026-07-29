<?php

namespace Tests\Feature;

use App\Models\QuizDog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The anonymous quiz save, exactly as the results page calls it.
 *
 * Two things broke in production and both are pinned here. The theme posted to the legacy
 * /shopify/dog/save-quiz-dog, which sits behind the server-to-server api.secret — from a
 * browser that answers 401, so the quiz saved nothing. And the public replacement returned
 * only {data:{quizDog:{id}}}, while the theme's extractDogIdFromResponse() reads
 * payload.data.id first and otherwise falls through to payload.data.quizDog — an OBJECT —
 * storing "[object Object]" as the quiz-dog id and silently breaking the cart sync.
 */
class QuizSaveContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_with_no_account_can_save_a_quiz(): void
    {
        $response = $this->postJson('/storefront/quiz-dogs', [
            'name' => 'רקסי',
            'weight' => 10,
            'age' => 3,
            'variants' => ['39357390782621'],
        ], ['Origin' => 'https://millsforpets.com']);

        $response->assertOk()->assertJsonPath('ok', true);

        $this->assertSame(1, QuizDog::query()->count());
    }

    public function test_the_response_carries_a_flat_string_id_the_theme_can_read(): void
    {
        $response = $this->postJson('/storefront/quiz-dogs', ['name' => 'רקסי', 'weight' => 10]);

        $id = $response->json('data.id');

        // The FIRST field the theme's extractor checks. Without it, the extractor falls
        // through to data.quizDog — an object — and stores "[object Object]".
        $this->assertIsString($id);
        $this->assertNotSame('', $id);
        $this->assertSame($id, $response->json('data.quizDog.id'));
        $this->assertSame($id, QuizDog::query()->firstOrFail()->public_id);
    }

    public function test_an_empty_quiz_is_refused(): void
    {
        $this->postJson('/storefront/quiz-dogs', [])->assertStatus(422);

        $this->assertSame(0, QuizDog::query()->count());
    }

    public function test_the_legacy_route_still_demands_the_api_secret(): void
    {
        // Configured explicitly: the middleware passes everything through when no secret is
        // set outside production (v1 parity), so without this line the test would prove
        // nothing at all.
        config(['api.secret' => 'test-secret']);

        // The public route is the exception, not a precedent: the legacy server-to-server
        // surface stays locked, or the whole /shopify/* API is one header away from open.
        $this->postJson('/shopify/dog/save-quiz-dog', ['name' => 'רקסי'])
            ->assertStatus(401);
    }
}
