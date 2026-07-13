<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\QuizDog;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;
use Throwable;

/**
 * Exercises EVERY endpoint in the frozen contract and asserts none of them blows
 * up. This is the "does the whole surface actually work" check — not a sample.
 *
 * Two things are proven:
 *  1. Every route is reachable and answers with an EXPECTED status (never a 500).
 *  2. No route in the app is left untested — the coverage assertion at the bottom
 *     fails if someone adds an endpoint and forgets to exercise it here.
 *
 * Endpoints that legitimately depend on an external system that is not wired up in
 * tests (Shopify draft orders, the PayMe hosted page) must DEGRADE GRACEFULLY —
 * 503/502 with a clear code, never a stack trace.
 */
class EveryEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const API_SECRET = 'test-api-secret';

    private const WEBHOOK_SECRET = 'test-webhook-secret';

    private Customer $customer;

    private Subscription $subscription;

    private Dog $dog;

    private QuizDog $quizDog;

    /** @var array<string, int> uri+method => status actually returned */
    private array $hit = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'api.secret' => self::API_SECRET,
            'shopify.webhook_secret' => self::WEBHOOK_SECRET,
            'shopify.storefront_token_secret' => 'test-storefront-secret',
        ]);

        $this->customer = Customer::query()->create([
            'shopify_customer_id' => '7001',
            'email' => 'every@example.com',
            'first_name' => 'Every',
            'last_name' => 'Endpoint',
        ]);

        $this->subscription = new Subscription;
        $this->subscription->fill([
            'customer_id' => $this->customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->toDateString(),
            'draft_order_id' => 'gid://shopify/DraftOrder/555',
        ]);
        $this->subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $this->dog = Dog::query()->create([
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->subscription->id,
            'name' => 'Milo',
            'status' => 'active',
            'selected_variants' => ['v-1'],
            'addons_products' => ['a-1'],
        ]);

        $this->quizDog = QuizDog::query()->create([
            'public_id' => 'quiz-abc',
            'payload' => ['name' => 'QuizPup', 'weight' => 9],
        ]);
    }

    // --- helpers -------------------------------------------------------------

    private function apiHeaders(): array
    {
        return ['X-API-Secret' => self::API_SECRET];
    }

    private function storefrontHeaders(): array
    {
        return ['Authorization' => 'Bearer '.StorefrontToken::mint('7001')];
    }

    private function webhookHeaders(array $body): array
    {
        $raw = json_encode($body);

        return [
            'X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $raw, self::WEBHOOK_SECRET, true)),
        ];
    }

    /**
     * Call an endpoint and assert it returned one of the statuses we expect.
     * Records the hit so the coverage assertion can prove nothing was missed.
     *
     * @param  list<int>  $expected
     */
    private function call_(string $method, string $uri, array $body = [], array $headers = [], array $expected = [200]): void
    {
        $response = $this->json($method, $uri, $body, $headers);
        $status = $response->getStatusCode();

        $this->hit[$this->key($method, $uri)] = $status;

        $this->assertContains(
            $status,
            $expected,
            "{$method} {$uri} returned {$status} (expected ".implode('|', $expected).")\n"
            .mb_substr((string) $response->getContent(), 0, 400),
        );
    }

    /**
     * Record the hit against the ROUTE PATTERN (api/subscriptions/{id}), not the
     * concrete path, so coverage can be compared against the route table.
     */
    private function key(string $method, string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH) ?: $uri;
        $method = strtoupper($method);

        try {
            $route = Route::getRoutes()->match(Request::create($path, $method));

            return $method.' '.trim($route->uri(), '/');
        } catch (Throwable) {
            return $method.' '.trim($path, '/');
        }
    }

    // --- the sweep -----------------------------------------------------------

    public function test_every_endpoint_in_the_contract_responds_correctly(): void
    {
        $sub = $this->subscription->id;
        $dog = $this->dog->id;
        $cust = $this->customer->id;

        // ---------- /api : subscriptions ----------
        $this->call_('GET', '/api/ping', [], $this->apiHeaders());
        $this->call_('GET', '/api/subscriptions', [], $this->apiHeaders());
        $this->call_('GET', "/api/subscriptions/{$sub}", [], $this->apiHeaders());
        $this->call_('GET', '/api/subscriptions/due-today', [], $this->apiHeaders());
        $this->call_('GET', "/api/subscriptions/customer/{$cust}", [], $this->apiHeaders());
        $this->call_('GET', '/api/subscriptions/status/active', [], $this->apiHeaders());
        $this->call_('GET', '/api/subscriptions/by-draft-order/gid://shopify/DraftOrder/555', [], $this->apiHeaders());
        $this->call_('GET', "/api/subscriptions/{$sub}/products", [], $this->apiHeaders());
        $this->call_('PATCH', "/api/subscriptions/{$sub}", ['frequency' => 'Every 2 Months'], $this->apiHeaders());
        $this->call_('PATCH', "/api/subscriptions/{$sub}/add-dog", ['dogId' => (string) $dog], $this->apiHeaders());
        $this->call_('PATCH', "/api/subscriptions/{$sub}/remove-dog", ['dogId' => (string) $dog], $this->apiHeaders());
        $this->call_('POST', '/api/subscriptions', ['customer' => (string) $cust, 'frequency' => 'Monthly'], $this->apiHeaders(), [201]);
        $this->call_('POST', '/api/subscriptions/from-order', [
            'id' => '9001', 'customer' => ['id' => '7001'],
        ], $this->apiHeaders());

        // Shopify-dependent: must degrade gracefully, never 500.
        $this->call_('POST', "/api/subscriptions/{$sub}/draft-order", [], $this->apiHeaders(), [503, 502]);
        $this->call_('PATCH', "/api/subscriptions/{$sub}/draft-order", [], $this->apiHeaders(), [503, 502]);
        $this->call_('GET', "/api/subscriptions/{$sub}/draft-order", [], $this->apiHeaders(), [503, 502]);

        // ---------- /api : dogs ----------
        $this->call_('POST', '/api/dogs/quiz', ['name' => 'Q'], $this->apiHeaders());
        $this->call_('POST', '/api/dogs/recommend', [
            'weight' => 10, 'age' => 3, 'activity' => 1, 'body' => 1, 'neutered' => true,
        ], $this->apiHeaders());
        $this->call_('POST', '/api/dogs/link-quiz', [
            'customerId' => (string) $cust, 'quizDogId' => 'quiz-abc', 'variants' => ['v-2'],
        ], $this->apiHeaders());
        $this->call_('PATCH', '/api/dogs/addons/add', ['dogId' => (string) $dog, 'variantId' => 'a-2'], $this->apiHeaders());
        $this->call_('PATCH', '/api/dogs/addons/remove', ['dogId' => (string) $dog, 'variantId' => 'a-2'], $this->apiHeaders());
        $this->call_('PATCH', '/api/dogs/subscription-variant', ['dogId' => (string) $dog, 'variantId' => 'v-3'], $this->apiHeaders());
        $this->call_('PATCH', '/api/dogs/subscription-status', ['dogId' => (string) $dog, 'status' => 'active'], $this->apiHeaders());
        $this->call_('POST', '/api/dogs/status', ['dogId' => (string) $dog, 'status' => 'active'], $this->apiHeaders());
        $this->call_('PATCH', '/api/dogs/update', ['dogId' => (string) $dog, 'updates' => ['name' => 'Milo2']], $this->apiHeaders());
        $this->call_('POST', '/api/dogs/remove-from-customer', ['dogId' => (string) $dog, 'customerId' => (string) $cust], $this->apiHeaders());

        // ---------- /api : orders + cron ----------
        $this->call_('GET', '/api/orders/process-billing', [], $this->apiHeaders());
        $this->call_('POST', '/api/orders/draft', ['subscriptionId' => (string) $sub], $this->apiHeaders(), [503, 502]);
        $this->call_('GET', '/api/cron/status', [], $this->apiHeaders());
        $this->call_('POST', '/api/cron/init', [], $this->apiHeaders());
        $this->call_('POST', '/api/cron/trigger', [], $this->apiHeaders());
        $this->call_('POST', '/api/cron/stop', [], $this->apiHeaders());
        $this->call_('POST', '/api/cron/start', [], $this->apiHeaders());

        // ---------- webhooks (HMAC, no API secret) ----------
        $paid = ['id' => '9002', 'customer' => ['id' => '7001'], 'note_attributes' => []];
        $this->call_('POST', '/api/orders/webhook/order-paid', $paid, $this->webhookHeaders($paid));

        $created = ['id' => '7002', 'email' => 'hook@example.com', 'first_name' => 'Hook'];
        $this->call_('POST', '/api/customers/webhook/created', $created, $this->webhookHeaders($created));
        $this->call_('POST', '/api/customers/webhook/updated', $created, $this->webhookHeaders($created));

        $deleted = ['id' => '7002'];
        $this->call_('POST', '/api/customers/webhook/deleted', $deleted, $this->webhookHeaders($deleted));

        // ---------- legacy NestJS aliases ----------
        $this->call_('GET', '/order', [], $this->apiHeaders());
        $this->call_('GET', '/shopify/dog', [], $this->apiHeaders());
        $this->call_('GET', '/shopify/subscription', [], $this->apiHeaders());
        $this->call_('GET', "/shopify/subscription/{$sub}", [], $this->apiHeaders());
        $this->call_('GET', '/shopify/subscription/active/charge-cycle-today', [], $this->apiHeaders());
        $this->call_('GET', "/shopify/subscription/customer/{$cust}", [], $this->apiHeaders());
        $this->call_('GET', '/shopify/subscription/status/active', [], $this->apiHeaders());
        $this->call_('GET', '/shopify/subscription/draft-order/gid://shopify/DraftOrder/555', [], $this->apiHeaders());
        $this->call_('GET', "/shopify/subscription/{$sub}/products", [], $this->apiHeaders());
        $this->call_('PATCH', "/shopify/subscription/{$sub}/add-dog", ['dogId' => (string) $dog], $this->apiHeaders());
        $this->call_('PATCH', "/shopify/subscription/{$sub}/remove-dog", ['dogId' => (string) $dog], $this->apiHeaders());
        $this->call_('POST', '/shopify/subscription', ['customer' => (string) $cust], $this->apiHeaders(), [201]);
        $this->call_('POST', '/shopify/subscription/from-order', ['id' => '9003', 'customer' => ['id' => '7001']], $this->apiHeaders());
        $this->call_('PATCH', "/shopify/subscription?id={$sub}", ['frequency' => 'Monthly'], $this->apiHeaders());
        $this->call_('POST', "/shopify/subscription/{$sub}/create-draft-order", [], $this->apiHeaders(), [503, 502]);
        $this->call_('PATCH', "/shopify/subscription/{$sub}/update-draft-order", [], $this->apiHeaders(), [503, 502]);
        $this->call_('GET', "/shopify/subscription/{$sub}/draft-order", [], $this->apiHeaders(), [503, 502]);

        $this->call_('POST', '/shopify/dog/save-quiz-dog', ['name' => 'L'], $this->apiHeaders());
        $this->call_('POST', '/shopify/dog/recommend', ['weight' => 10, 'age' => 3], $this->apiHeaders());
        $this->call_('POST', '/shopify/dog/link-quiz-dog-customer', [
            'customerId' => (string) $cust, 'quizDogId' => 'quiz-abc', 'variants' => ['v-4'],
        ], $this->apiHeaders());
        $this->call_('PATCH', '/shopify/dog/add-addon', ['dogId' => (string) $dog, 'variantId' => 'a-3'], $this->apiHeaders());
        $this->call_('PATCH', '/shopify/dog/remove-addon', ['dogId' => (string) $dog, 'variantId' => 'a-3'], $this->apiHeaders());
        $this->call_('PATCH', '/shopify/dog/change_subscription_variant', ['dogId' => (string) $dog, 'variantId' => 'v-5'], $this->apiHeaders());
        $this->call_('PATCH', '/shopify/dog/change_subscription_status', ['dogId' => (string) $dog, 'status' => 'active'], $this->apiHeaders());
        $this->call_('POST', '/shopify/dog/change_status', ['dogId' => (string) $dog, 'status' => 'active'], $this->apiHeaders());
        $this->call_('PATCH', '/shopify/dog/update', ['dogId' => (string) $dog, 'updates' => ['name' => 'L2']], $this->apiHeaders());
        $this->call_('POST', '/shopify/dog/remove-dog-from-customer', ['dogId' => (string) $dog, 'customerId' => (string) $cust], $this->apiHeaders());

        $this->call_('POST', '/order/create-draft-order', ['subscriptionId' => (string) $sub], $this->apiHeaders(), [503, 502]);
        $this->call_('GET', '/order/subscription', [], $this->apiHeaders());
        $this->call_('GET', '/order/cron/status', [], $this->apiHeaders());
        $this->call_('POST', '/order/cron/init', [], $this->apiHeaders());
        $this->call_('POST', '/order/cron/trigger', [], $this->apiHeaders());
        $this->call_('POST', '/order/cron/stop', [], $this->apiHeaders());
        $this->call_('POST', '/order/cron/start', [], $this->apiHeaders());

        // Cancellation, both ways — each on its own subscription, since it is terminal.
        $spareA = new Subscription;
        $spareA->fill(['customer_id' => $cust, 'payment_state' => PaymentState::PAYME->value, 'frequency_months' => 1]);
        $spareA->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();
        $this->call_('DELETE', "/api/subscriptions/{$spareA->id}", [], $this->apiHeaders());

        // …and the legacy collection form, which takes the id from ?id=.
        $spareB = new Subscription;
        $spareB->fill(['customer_id' => $cust, 'payment_state' => PaymentState::PAYME->value, 'frequency_months' => 1]);
        $spareB->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();
        $this->call_('DELETE', "/shopify/subscription?id={$spareB->id}", [], $this->apiHeaders());

        // ---------- storefront (token-gated) ----------
        $sf = $this->storefrontHeaders();
        $this->call_('POST', '/storefront/auth/otp/request', ['email' => 'every@example.com'], [], [200, 429]);
        $this->call_('POST', '/storefront/auth/otp/verify', ['email' => 'every@example.com', 'code' => '000000'], [], [401]);

        $this->call_('GET', '/storefront/me', [], $sf);
        $this->call_('PATCH', '/storefront/me/address', ['city' => 'Tel Aviv'], $sf);
        $this->call_('PATCH', "/storefront/me/subscription/{$sub}", ['frequency' => 'Monthly'], $sf);
        $this->call_('PATCH', "/storefront/me/subscription/{$sub}/add-dog", ['dogId' => (string) $dog], $sf);
        $this->call_('PATCH', "/storefront/me/dogs/{$dog}", ['updates' => ['name' => 'Milo3']], $sf);
        $this->call_('PATCH', "/storefront/me/dogs/{$dog}/change-variant", ['variantId' => 'v-9'], $sf);
        $this->call_('PATCH', "/storefront/me/dogs/{$dog}/addons/add", ['variantId' => 'a-9'], $sf);
        $this->call_('PATCH', "/storefront/me/dogs/{$dog}/addons/remove", ['variantId' => 'a-9'], $sf);
        $this->call_('POST', '/storefront/me/quiz-dogs', ['name' => 'SF'], $sf);
        $this->call_('POST', '/storefront/me/quiz-dogs/quiz-abc/link', ['variants' => ['v-7']], $sf);
        $this->call_('PATCH', "/storefront/me/subscription/{$sub}/remove-dog", ['dogId' => (string) $dog], $sf);
        $this->call_('POST', "/storefront/me/dogs/{$dog}/remove", [], $sf);

        // PayMe not configured in tests → graceful 502, never a stack trace.
        $this->call_('POST', '/storefront/me/payment-method/payme/session', [], $sf, [502, 404]);

        // The card-update return page (session_id is the credential).
        $this->get('/storefront/payment-method/payme-callback?session_id=nope')->assertOk();
        $this->hit['GET storefront/payment-method/payme-callback'] = 200;

        // ---------- coverage: did we miss anything? ----------
        $this->assertNoEndpointWasMissed();
    }

    /**
     * Every registered contract route must have been called above. If someone adds
     * an endpoint and doesn't exercise it, this fails — "all endpoints work" stays
     * a fact rather than a hope.
     */
    private function assertNoEndpointWasMissed(): void
    {
        $covered = array_keys($this->hit);
        $missed = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! preg_match('#^(api/|storefront/|shopify/(subscription|dog)|order)#', $uri)) {
                continue;
            }
            if (preg_match('#^shopify/(install|callback|webhooks)#', $uri)) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $key = $method.' '.trim($uri, '/');
                if (! in_array($key, $covered, true)) {
                    $missed[] = $key;
                }
            }
        }

        $missed = array_values(array_unique($missed));

        $this->assertSame([], $missed,
            "These endpoints exist but were never exercised:\n".implode("\n", $missed));

        // Sanity: we really did sweep the whole surface, not a handful.
        $this->assertGreaterThanOrEqual(80, count($covered),
            'Expected the full contract surface to be exercised.');
    }
}
