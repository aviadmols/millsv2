<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\SystemLog;
use RuntimeException;

/**
 * Activates the app's own checkout function — hide-paypal-on-subscriptions.
 *
 * Deploying a Shopify Function only makes it AVAILABLE. It runs for nobody until a
 * payment customization points at it, and Shopify's admin offers no way to create
 * one: Settings → Payments → Payment customizations lists what exists and nothing
 * more. An app that ships a function and no way to switch it on has shipped
 * nothing, which is exactly what happened here (27 Aug — deployed, screen empty).
 *
 * Idempotent: it finds the existing customization for our function and re-enables
 * it rather than stacking duplicates, so pressing the button twice is harmless.
 */
class PaymentCustomizationInstaller
{
    /** The function's handle in shopify.extension.toml — how we recognise ours. */
    public const FUNCTION_HANDLE = 'hide-paypal-on-subscriptions';

    private const TITLE = 'Hide PayPal on subscriptions';

    private const FUNCTIONS_QUERY = <<<'GQL'
    query {
      shopifyFunctions(first: 50) {
        nodes { id title apiType app { title } }
      }
    }
    GQL;

    private const EXISTING_QUERY = <<<'GQL'
    query {
      paymentCustomizations(first: 50) {
        nodes { id title enabled shopifyFunction { id } }
      }
    }
    GQL;

    private const CREATE_MUTATION = <<<'GQL'
    mutation($input: PaymentCustomizationInput!) {
      paymentCustomizationCreate(paymentCustomization: $input) {
        paymentCustomization { id }
        userErrors { field message }
      }
    }
    GQL;

    private const UPDATE_MUTATION = <<<'GQL'
    mutation($id: ID!, $input: PaymentCustomizationInput!) {
      paymentCustomizationUpdate(id: $id, paymentCustomization: $input) {
        paymentCustomization { id }
        userErrors { field message }
      }
    }
    GQL;

    public function __construct(private readonly ShopifyAdminClient $client) {}

    /**
     * Switch the function on. Returns what happened, for the admin to read.
     *
     * @return array{status: string, id?: string}
     *
     * @throws RuntimeException with a message meant for a person
     */
    public function activate(): array
    {
        if (! $this->client->isConnected()) {
            throw new RuntimeException('not_connected');
        }

        $functionId = $this->functionId();

        // Already pointed at our function? Re-enable rather than create a second one:
        // two customizations hiding the same method is a mess nobody can reason about.
        $existing = $this->existingFor($functionId);

        if ($existing !== null) {
            if ($existing['enabled'] ?? false) {
                return ['status' => 'already_active', 'id' => (string) $existing['id']];
            }

            $this->mutate(self::UPDATE_MUTATION, [
                'id' => $existing['id'],
                'input' => ['title' => self::TITLE, 'enabled' => true, 'functionId' => $functionId],
            ], 'paymentCustomizationUpdate');

            SystemLog::info('admin', 'the PayPal-hiding checkout function was re-enabled', [
                'payment_customization_id' => $existing['id'],
            ]);

            return ['status' => 'reactivated', 'id' => (string) $existing['id']];
        }

        $created = $this->mutate(self::CREATE_MUTATION, [
            'input' => ['title' => self::TITLE, 'enabled' => true, 'functionId' => $functionId],
        ], 'paymentCustomizationCreate');

        $id = (string) (data_get($created, 'paymentCustomization.id') ?? '');

        SystemLog::info('admin', 'the PayPal-hiding checkout function was activated', [
            'payment_customization_id' => $id,
        ]);

        return ['status' => 'activated', 'id' => $id];
    }

    /** Is it on right now? Drives the button's label, so it never lies about state. */
    public function isActive(): bool
    {
        if (! $this->client->isConnected()) {
            return false;
        }

        try {
            $existing = $this->existingFor($this->functionId());
        } catch (RuntimeException) {
            return false;
        }

        return (bool) ($existing['enabled'] ?? false);
    }

    /**
     * Our function's id, as Shopify knows it.
     *
     * Matched on the title the extension declares rather than a hardcoded gid: the id
     * is minted per app version, so pinning one would break on the next deploy.
     */
    private function functionId(): string
    {
        $result = $this->client->graphql(self::FUNCTIONS_QUERY);

        foreach ((array) data_get($result, 'data.shopifyFunctions.nodes', []) as $function) {
            $function = (array) $function;
            $title = (string) ($function['title'] ?? '');

            if ($title === self::FUNCTION_HANDLE || $title === self::TITLE) {
                return (string) $function['id'];
            }
        }

        // Deployed but not visible to this store — almost always a missing
        // `shopify app deploy`, or an app version that has not been released.
        throw new RuntimeException('function_not_found');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingFor(string $functionId): ?array
    {
        $result = $this->client->graphql(self::EXISTING_QUERY);

        foreach ((array) data_get($result, 'data.paymentCustomizations.nodes', []) as $node) {
            $node = (array) $node;

            if ((string) (data_get($node, 'shopifyFunction.id') ?? '') === $functionId) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function mutate(string $mutation, array $variables, string $field): array
    {
        $result = $this->client->graphql($mutation, $variables);
        $payload = (array) (data_get($result, 'data.'.$field) ?? []);

        $errors = (array) ($payload['userErrors'] ?? []);
        if ($errors !== []) {
            $message = implode('; ', array_map(
                static fn ($error) => (string) (data_get($error, 'message') ?? ''),
                $errors,
            ));

            // The one worth naming: the scope was added to the manifest but the store
            // still holds a token granted before it, so every call is refused.
            SystemLog::error('admin', 'Shopify refused to activate the checkout function', [
                'errors' => $message,
            ]);

            throw new RuntimeException($message !== '' ? $message : 'shopify_refused');
        }

        return $payload;
    }
}
