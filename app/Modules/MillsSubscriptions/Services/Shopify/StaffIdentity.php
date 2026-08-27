<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Who, by name, is the Shopify staff member behind this session token.
 *
 * The session token carries only `sub` — the staff id — so an app that stops there can
 * label its own audit trail nothing better than "Shopify staff #77123456". The name is
 * one token exchange away: swapping the session token for an ONLINE access token returns
 * `associated_user`, which is Shopify telling us who is looking.
 *
 * Deliberately not the staffMembers query: `read_users` is a Shopify Plus scope, and this
 * store is not on Plus (27 Aug). Token exchange has no such requirement.
 *
 * The online token itself is thrown away. It is not needed — the app's offline token does
 * every API call — and an access token kept for no reason is a liability, not an asset.
 */
class StaffIdentity
{
    private const EXCHANGE_GRANT = 'urn:ietf:params:oauth:grant-type:token-exchange';

    private const ID_TOKEN_TYPE = 'urn:ietf:params:oauth:token-type:id_token';

    private const ONLINE_TOKEN_TYPE = 'urn:shopify:params:oauth:token-type:online-access-token';

    /**
     * @return array{name: string, email: string}|null null when Shopify will not say
     */
    public function resolve(string $sessionToken, string $shopDomain): ?array
    {
        $apiKey = (string) config('shopify.api_key', '');
        $apiSecret = (string) config('shopify.api_secret', '');

        if ($apiKey === '' || $apiSecret === '' || $sessionToken === '' || $shopDomain === '') {
            return null;
        }

        try {
            $response = Http::asJson()
                ->connectTimeout(10)
                ->timeout(15)
                ->post("https://{$shopDomain}/admin/oauth/access_token", [
                    'client_id' => $apiKey,
                    'client_secret' => $apiSecret,
                    'grant_type' => self::EXCHANGE_GRANT,
                    'subject_token' => $sessionToken,
                    'subject_token_type' => self::ID_TOKEN_TYPE,
                    'requested_token_type' => self::ONLINE_TOKEN_TYPE,
                ]);

            if (! $response->successful()) {
                // Not fatal, and not silent: the sign-in still works under the fallback
                // label, but nobody should have to guess why the name never appeared.
                SystemLog::warning('auth', 'Shopify would not name the staff member behind the session', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $user = (array) ($response->json('associated_user') ?? []);

            $name = trim(implode(' ', array_filter([
                (string) ($user['first_name'] ?? ''),
                (string) ($user['last_name'] ?? ''),
            ])));

            $email = trim((string) ($user['email'] ?? ''));

            if ($name === '' && $email === '') {
                return null;
            }

            return ['name' => $name, 'email' => $email];
        } catch (Throwable $e) {
            SystemLog::warning('auth', 'could not read the Shopify staff member behind the session', [
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
