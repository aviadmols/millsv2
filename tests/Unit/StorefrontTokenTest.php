<?php

namespace Tests\Unit;

use App\Support\StorefrontToken;
use Tests\TestCase;

class StorefrontTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shopify.storefront_token_secret' => 'unit-test-secret',
            'shopify.storefront_token_max_age' => 86400,
        ]);
    }

    public function test_mint_then_verify_returns_the_subject(): void
    {
        $token = StorefrontToken::mint('30801169416496');

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.[a-f0-9]{64}$/', $token);
        $this->assertSame('30801169416496', StorefrontToken::verify($token));
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $token = StorefrontToken::mint('123');
        $tampered = substr($token, 0, -1).($token[strlen($token) - 1] === 'a' ? 'b' : 'a');

        $this->assertNull(StorefrontToken::verify($tampered));
    }

    public function test_expired_token_is_rejected(): void
    {
        $old = StorefrontToken::mint('123', time() - 90000); // > 86400 max age

        $this->assertNull(StorefrontToken::verify($old));
    }

    public function test_wrong_secret_is_rejected(): void
    {
        $token = StorefrontToken::mint('123');
        config(['shopify.storefront_token_secret' => 'a-different-secret']);

        $this->assertNull(StorefrontToken::verify($token));
    }

    public function test_malformed_token_is_rejected(): void
    {
        $this->assertNull(StorefrontToken::verify('not-a-token'));
        $this->assertNull(StorefrontToken::verify('123.456.zzz'));
    }
}
