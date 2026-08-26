<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Signed into Shopify ⇒ signed into this app.
 *
 * The gate is the App Bridge session token and nothing else: it is signed with our app
 * secret, minted for our API key, and names the shop. Everything here is about what
 * must NOT get in — a forged signature, another shop's token, an expired one — and
 * about the one behaviour that keeps the normal login alive: an invalid token never
 * 401s the panel, it just falls through to the email/password form.
 */
class ShopifyEmbeddedSsoTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shpss_test_secret';

    private const API_KEY = 'test-api-key';

    private const SHOP = 'millsforpets.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shopify.api_secret', self::SECRET);
        config()->set('shopify.api_key', self::API_KEY);
        config()->set('shopify.shop_domain', self::SHOP);
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = [], string $secret = self::SECRET): string
    {
        $claims = array_replace([
            'iss' => 'https://'.self::SHOP.'/admin',
            'dest' => 'https://'.self::SHOP,
            'aud' => self::API_KEY,
            'sub' => '77123456',
            'exp' => time() + 60,
            'nbf' => time() - 5,
            'iat' => time(),
        ], $overrides);

        $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = $encode($claims);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $header.'.'.$payload, $secret, true)
        ), '+/', '-_'), '=');

        return $header.'.'.$payload.'.'.$signature;
    }

    public function test_a_shopify_staff_member_lands_in_the_panel_without_logging_in(): void
    {
        $this->get('/admin?id_token='.$this->token())->assertSuccessful();

        $this->assertTrue(Auth::check());
        $this->assertSame(1, User::query()->count());
    }

    public function test_each_staff_member_gets_their_own_account_so_the_timeline_names_them(): void
    {
        $this->get('/admin?id_token='.$this->token(['sub' => '111']))->assertSuccessful();
        $first = Auth::id();

        // A different person, in the same store.
        Auth::logout();
        $this->flushSession();
        $this->get('/admin?id_token='.$this->token(['sub' => '222']))->assertSuccessful();

        $this->assertNotSame($first, Auth::id());
        $this->assertSame(2, User::query()->count());
    }

    public function test_the_same_staff_member_returning_reuses_their_account(): void
    {
        $this->get('/admin?id_token='.$this->token())->assertSuccessful();
        Auth::logout();
        $this->flushSession();
        $this->get('/admin?id_token='.$this->token())->assertSuccessful();

        $this->assertSame(1, User::query()->count());
    }

    public function test_a_forged_signature_gets_the_login_form_not_the_panel(): void
    {
        $this->get('/admin?id_token='.$this->token([], 'not-our-secret'))
            ->assertRedirectContains('/admin/login');

        $this->assertFalse(Auth::check());
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_token_for_another_shop_cannot_open_this_one(): void
    {
        // Correctly signed and perfectly valid — for somebody else's store.
        $this->get('/admin?id_token='.$this->token([
            'iss' => 'https://someone-else.myshopify.com/admin',
            'dest' => 'https://someone-else.myshopify.com',
        ]))->assertRedirectContains('/admin/login');

        $this->assertFalse(Auth::check());
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->get('/admin?id_token='.$this->token(['exp' => time() - 120, 'nbf' => time() - 300]))
            ->assertRedirectContains('/admin/login');

        $this->assertFalse(Auth::check());
    }

    public function test_a_token_minted_for_a_different_app_is_refused(): void
    {
        $this->get('/admin?id_token='.$this->token(['aud' => 'someone-elses-api-key']))
            ->assertRedirectContains('/admin/login');

        $this->assertFalse(Auth::check());
    }

    public function test_the_alg_none_trick_is_refused(): void
    {
        // A verifier that trusts the token's own `alg` accepts an unsigned token.
        $encode = fn (array $d) => rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
        $forged = $encode(['alg' => 'none', 'typ' => 'JWT']).'.'.$encode([
            'iss' => 'https://'.self::SHOP.'/admin',
            'dest' => 'https://'.self::SHOP,
            'aud' => self::API_KEY,
            'sub' => '1',
            'exp' => time() + 60,
        ]).'.';

        $this->get('/admin?id_token='.$forged)->assertRedirectContains('/admin/login');
        $this->assertFalse(Auth::check());
    }

    public function test_the_ordinary_login_still_works_when_no_token_is_present(): void
    {
        // Nobody embedded: the panel must behave exactly as it did before SSO existed.
        $this->get('/admin')->assertRedirectContains('/admin/login');

        $this->actingAs(User::factory()->create());
        $this->get('/admin')->assertSuccessful();
    }
}
