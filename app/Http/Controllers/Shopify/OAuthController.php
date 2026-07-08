<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shopify OAuth install flow (ARCHITECTURE.md §1b) — captures an OFFLINE access
 * token. install() redirects to Shopify's authorize; callback() verifies the
 * query HMAC + single-use state nonce, exchanges the code, and hands off to
 * ShopInstaller. Legacy redirect flow (no App Bridge) — single-store custom app.
 */
class OAuthController extends Controller
{
    private const STATE_TTL_MINUTES = 5;

    public function install(Request $request)
    {
        $shop = $this->sanitizeShop((string) $request->query('shop', ''));
        if ($shop === null) {
            abort(400, 'Missing or invalid ?shop');
        }

        $state = Str::random(40);
        Cache::put($this->stateKey($state), $shop, now()->addMinutes(self::STATE_TTL_MINUTES));

        $params = http_build_query([
            'client_id' => (string) config('shopify.api_key'),
            'scope' => (string) config('shopify.oauth_scopes'),
            'redirect_uri' => route('shopify.callback'),
            'state' => $state,
        ]);

        return redirect()->away("https://{$shop}/admin/oauth/authorize?{$params}");
    }

    public function callback(Request $request, ShopInstaller $installer)
    {
        if (! $this->verifyQueryHmac($request)) {
            abort(401, 'HMAC validation failed');
        }

        $shop = $this->sanitizeShop((string) $request->query('shop', ''));
        $state = (string) $request->query('state', '');
        if ($shop === null || Cache::pull($this->stateKey($state)) !== $shop) {
            abort(401, 'Invalid OAuth state');
        }

        $token = Http::asJson()->post("https://{$shop}/admin/oauth/access_token", [
            'client_id' => (string) config('shopify.api_key'),
            'client_secret' => (string) config('shopify.api_secret'),
            'code' => (string) $request->query('code', ''),
        ])->json();

        $accessToken = (string) ($token['access_token'] ?? '');
        if ($accessToken === '') {
            abort(502, 'Token exchange failed');
        }

        $installer->install($shop, $accessToken, (string) ($token['scope'] ?? ''));

        return redirect()->away("https://{$shop}/admin/apps");
    }

    private function sanitizeShop(string $shop): ?string
    {
        $shop = strtolower(trim($shop));

        return preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop) === 1 ? $shop : null;
    }

    private function verifyQueryHmac(Request $request): bool
    {
        $secret = (string) config('shopify.api_secret', '');
        if ($secret === '') {
            return false;
        }

        $params = $request->query();
        $hmac = (string) ($params['hmac'] ?? '');
        unset($params['hmac'], $params['signature']);
        ksort($params);

        $message = urldecode(http_build_query($params));
        $calculated = hash_hmac('sha256', $message, $secret);

        return $hmac !== '' && hash_equals($calculated, $hmac);
    }

    private function stateKey(string $state): string
    {
        return 'shopify_oauth_state:'.$state;
    }
}
