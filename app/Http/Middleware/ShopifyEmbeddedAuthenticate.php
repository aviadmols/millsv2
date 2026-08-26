<?php

namespace App\Http\Middleware;

use App\Models\SystemLog;
use App\Models\User;
use App\Modules\MillsSubscriptions\Services\Shopify\SessionTokenVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single sign-on from Shopify Admin: whoever is signed into the store is signed into
 * this app, with no second password.
 *
 * When the panel loads inside Shopify's iframe, App Bridge attaches a session token —
 * a one-minute HS256 JWT signed with our app secret (`Authorization: Bearer`, or
 * `?id_token=` on the very first load, before App Bridge can set a header). A valid
 * one proves two things Shopify already checked: this is a real staff member, and
 * this is our store.
 *
 * FAILS OPEN TO THE LOGIN FORM, NEVER TO A 401. No token, a bad token, a token for
 * some other shop — the request simply passes through unauthenticated and the normal
 * email/password login still serves it. Somebody opening mills.lets.co.il/admin
 * directly must keep working exactly as before.
 *
 * ATTRIBUTION: each Shopify staff member gets their OWN local user, keyed by the
 * token's `sub` claim. A shared "Shopify" login would make every note and every admin
 * action in the timeline read as the same anonymous person — which is precisely the
 * question an audit trail exists to answer.
 */
class ShopifyEmbeddedAuthenticate
{
    public function __construct(private readonly SessionTokenVerifier $verifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $jwt = $this->extractToken($request);

        if ($jwt === '') {
            return $next($request);       // not an embedded load — nothing to do
        }

        $claims = $this->verifier->verify($jwt);

        if ($claims === null) {
            return $next($request);
        }

        // The shop comes ONLY from the verified dest claim, never from a query
        // parameter: a token minted for another store must not open this one.
        $shop = $this->verifier->shopDomain($claims);
        $ours = strtolower(trim((string) config('shopify.shop_domain', '')));

        if ($shop === '' || $ours === '' || $shop !== $ours) {
            return $next($request);
        }

        $user = $this->userFor((string) ($claims['sub'] ?? ''), $shop);

        if ($user === null) {
            return $next($request);
        }

        /*
         * Log in ONCE per session. Auth::login() regenerates the session id, and doing
         * that on every embedded request destabilises the multi-request Livewire cycle
         * inside the iframe (open modal → confirm → save lands on a session that has
         * already migrated away). Re-login only when nobody is signed in, or when the
         * signed-in user is somebody else.
         */
        if (! Auth::check() || (int) Auth::id() !== (int) $user->getKey()) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        return $next($request);
    }

    /**
     * The local user for this Shopify staff member — found by the synthetic email the
     * `sub` claim maps to, or created on first sight.
     *
     * The password is random and never shared: this account exists to be logged in
     * THROUGH Shopify, and a staff member who leaves the store loses access with it.
     */
    private function userFor(string $sub, string $shop): ?User
    {
        $sub = trim($sub);

        if ($sub === '') {
            return null;
        }

        $email = 'shopify-'.preg_replace('/[^a-zA-Z0-9]/', '', $sub).'@'.$shop;
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $existing;
        }

        $user = User::query()->create([
            'name' => __('users.shopify_staff', ['id' => $sub]),
            'email' => $email,
            'password' => Str::random(64),
        ]);

        SystemLog::info('auth', 'a Shopify staff member opened the app for the first time', [
            'shopify_user_id' => $sub,
            'user_id' => $user->getKey(),
        ]);

        return $user;
    }

    private function extractToken(Request $request): string
    {
        $bearer = (string) $request->bearerToken();

        return $bearer !== '' ? $bearer : (string) $request->query('id_token', '');
    }
}
