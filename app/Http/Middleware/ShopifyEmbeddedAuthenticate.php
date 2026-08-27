<?php

namespace App\Http\Middleware;

use App\Models\SystemLog;
use App\Models\User;
use App\Modules\MillsSubscriptions\Services\Shopify\SessionTokenVerifier;
use App\Modules\MillsSubscriptions\Services\Shopify\StaffIdentity;
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
    public function __construct(
        private readonly SessionTokenVerifier $verifier,
        private readonly StaffIdentity $staff,
    ) {}

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

        $user = $this->userFor((string) ($claims['sub'] ?? ''), $shop, $jwt);

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
    private function userFor(string $sub, string $shop, string $sessionToken): ?User
    {
        $sub = trim($sub);

        if ($sub === '') {
            return null;
        }

        $fallbackEmail = 'shopify-'.preg_replace('/[^a-zA-Z0-9]/', '', $sub).'@'.$shop;
        $existing = User::query()->where('email', $fallbackEmail)->first();

        /*
         * Ask Shopify who this is — but only when it would change something. The exchange
         * is an HTTP round trip, and this middleware runs on EVERY embedded request; doing
         * it each time would put Shopify in the path of every page load. Once at creation,
         * and once more for anyone still wearing the "#77123456" placeholder from before
         * this existed, is enough.
         */
        $identity = ($existing === null || $this->isPlaceholderName($existing->name))
            ? $this->staff->resolve($sessionToken, $shop)
            : null;

        if ($existing !== null) {
            if ($identity !== null && $identity['name'] !== '') {
                $existing->forceFill(['name' => $identity['name']])->save();
            }

            return $existing;
        }

        /*
         * A staff member whose Shopify email already belongs to a local admin IS that
         * admin — the person who set the system up and now opens it from inside Shopify.
         * Claiming the existing account keeps one identity per human, instead of leaving
         * their notes signed by two different names.
         */
        if (($identity['email'] ?? '') !== '') {
            $byEmail = User::query()->where('email', $identity['email'])->first();

            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        $user = User::query()->create([
            'name' => $identity['name'] ?? '' ?: __('users.shopify_staff', ['id' => $sub]),
            'email' => $identity['email'] ?? '' ?: $fallbackEmail,
            'password' => Str::random(64),
        ]);

        SystemLog::info('auth', 'a Shopify staff member opened the app for the first time', [
            'shopify_user_id' => $sub,
            'user_id' => $user->getKey(),
            'named' => $identity !== null,
        ]);

        return $user;
    }

    /** Still the generated "צוות שופיפיי #123" label, i.e. we never learned their name. */
    private function isPlaceholderName(?string $name): bool
    {
        return $name === null
            || trim($name) === ''
            || str_contains((string) $name, '#');
    }

    private function extractToken(Request $request): string
    {
        $bearer = (string) $request->bearerToken();

        return $bearer !== '' ? $bearer : (string) $request->query('id_token', '');
    }
}
