<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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

    public function test_the_staff_member_is_named_by_shopify_not_by_their_id(): void
    {
        /*
         * The session token carries only `sub`, so without asking Shopify the audit trail
         * says "Shopify staff #77123456" — which answers nobody's question about who
         * changed a subscription. A token exchange returns associated_user; the
         * staffMembers query would too, but read_users is a Plus scope and this store is
         * not on Plus.
         */
        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpua_online_token',
                'associated_user' => [
                    'id' => 77123456,
                    'first_name' => 'רונית',
                    'last_name' => 'לוי',
                    'email' => 'ronit@millsforpets.com',
                ],
            ]),
        ]);

        $this->get('/admin?id_token='.$this->token())->assertSuccessful();

        $user = User::query()->firstOrFail();
        $this->assertSame('רונית לוי', $user->name);
        $this->assertSame('ronit@millsforpets.com', $user->email);
    }

    public function test_a_staff_member_whose_email_is_already_an_admin_reuses_that_account(): void
    {
        // The person who set the system up, now opening it from inside Shopify. One human,
        // one identity — otherwise their notes end up signed by two different names.
        $existing = User::factory()->create(['name' => 'אביעד', 'email' => 'aviadmols@gmail.com']);

        Http::fake([
            '*/admin/oauth/access_token' => Http::response([
                'associated_user' => ['first_name' => 'Aviad', 'last_name' => '', 'email' => 'aviadmols@gmail.com'],
            ]),
        ]);

        $this->get('/admin?id_token='.$this->token())->assertSuccessful();

        $this->assertSame($existing->id, Auth::id());
        $this->assertSame(1, User::query()->count());
    }

    public function test_an_id_labelled_account_from_before_gets_its_real_name_on_the_next_visit(): void
    {
        // Backfill: everyone provisioned before this existed still wears the placeholder.
        Http::fake([
            '*/admin/oauth/access_token' => Http::sequence()
                ->push([])                                        // first visit: Shopify says nothing
                ->push(['associated_user' => [                    // and on the next one, it does
                    'first_name' => 'רונית',
                    'last_name' => 'לוי',
                    'email' => 'ronit@millsforpets.com',
                ]]),
        ]);

        $this->get('/admin?id_token='.$this->token())->assertSuccessful();
        $this->assertStringContainsString('#', User::query()->firstOrFail()->name);

        Auth::logout();
        $this->flushSession();

        $this->get('/admin?id_token='.$this->token())->assertSuccessful();

        $this->assertSame('רונית לוי', User::query()->firstOrFail()->name);
        $this->assertSame(1, User::query()->count());
    }

    public function test_shopify_refusing_to_name_them_still_lets_them_in(): void
    {
        // The sign-in must never depend on the nicety. They get in under the id label.
        Http::fake(['*/admin/oauth/access_token' => Http::response([], 401)]);

        $this->get('/admin?id_token='.$this->token())->assertSuccessful();

        $this->assertTrue(Auth::check());
        $this->assertStringContainsString('#', User::query()->firstOrFail()->name);
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
