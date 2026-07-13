<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Shopify theme calls this app from the browser with `mode: "cors"`.
 *
 * Laravel's default only allows `api/*`, which silently excluded the ENTIRE personal area
 * (`/storefront/*`) and the quiz (`/shopify/*`, `/order/*`). Every one of those calls was
 * blocked by the browser before it ever reached us — so the theme could have every URL
 * right and still do nothing at all.
 *
 * These tests exist because that failure is invisible from the server's side: the request
 * never arrives, so there is nothing in the logs to find.
 */
class CorsTest extends TestCase
{
    use RefreshDatabase;

    private const STOREFRONT = 'https://millsforpets.com';

    /** Every route the live theme actually calls from a browser. */
    public static function themeRoutes(): array
    {
        return [
            'personal area' => ['/storefront/me'],
            'subscription write' => ['/storefront/me/subscription/1'],
            'card update' => ['/storefront/me/payment-method/payme/session'],
            'OTP login' => ['/storefront/auth/otp/request'],
            'quiz (api)' => ['/api/dogs/quiz'],
            'quiz (legacy)' => ['/shopify/dog/save-quiz-dog'],
            'subscription (legacy)' => ['/shopify/subscription/1'],
            'order (legacy)' => ['/order/cron/status'],
        ];
    }

    #[DataProvider('themeRoutes')]
    public function test_the_storefront_may_call_it(string $path): void
    {
        // The browser's preflight. If this is not answered, the real request is never sent.
        $response = $this->call('OPTIONS', $path, server: [
            'HTTP_ORIGIN' => self::STOREFRONT,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $this->assertSame(
            self::STOREFRONT,
            $response->headers->get('Access-Control-Allow-Origin'),
            "{$path} does not allow the storefront — the browser will block it",
        );

        $this->assertStringContainsStringIgnoringCase(
            'authorization',
            (string) $response->headers->get('Access-Control-Allow-Headers'),
            "{$path} must accept the bearer token header",
        );
    }

    public function test_the_actual_response_carries_the_origin_too(): void
    {
        // A preflight that passes is not enough — the real response must carry it as well.
        $response = $this->getJson('/storefront/me', ['Origin' => self::STOREFRONT]);

        $this->assertSame(self::STOREFRONT, $response->headers->get('Access-Control-Allow-Origin'));
        $response->assertStatus(401);   // still unauthenticated — CORS is not authentication
    }

    public function test_a_shopify_preview_domain_is_allowed(): void
    {
        // A theme must be testable on an unpublished preview before it goes live.
        $response = $this->call('OPTIONS', '/storefront/me', server: [
            'HTTP_ORIGIN' => 'https://abc123.shopifypreview.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertSame(
            'https://abc123.shopifypreview.com',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }

    public function test_an_unknown_site_is_not_allowed(): void
    {
        // These routes carry a bearer token. A wildcard origin would let any site on the
        // internet drive them with a token it got hold of.
        $response = $this->call('OPTIONS', '/storefront/me', server: [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);

        $this->assertNotSame(
            'https://evil.example.com',
            $response->headers->get('Access-Control-Allow-Origin'),
        );
    }
}
