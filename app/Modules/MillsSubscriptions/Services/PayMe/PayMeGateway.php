<?php

namespace App\Modules\MillsSubscriptions\Services\PayMe;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayResult;
use App\Domain\Billing\PaymentReference;
use App\Support\PaymentContextMasker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * PayMe implementation of the gateway contract.
 *
 * Two properties here are load-bearing for not charging a customer twice:
 *
 * 1. The idempotency key is SENT to PayMe as `transaction_id`. PayMe dedupes on it, so
 *    if we ever repeat a charge it collapses into the original sale instead of taking
 *    the money again. Previously nothing was sent, and PayMe had no way to know.
 *
 * 2. A lost answer is reported as AMBIGUOUS, never as a decline. A read timeout after
 *    the card was debited looks exactly like a refusal from here; treating the two the
 *    same is how the money gets taken twice.
 */
class PayMeGateway implements PaymentGateway
{
    public function __construct(private readonly PaymeClient $client) {}

    public function chargeWithReference(
        string $reference,
        int $amountAgorot,
        string $idempotencyKey,
        array $opts = [],
    ): GatewayResult {
        if (! $this->client->isConfigured()) {
            return GatewayResult::failure('not_configured', 'PayMe API is not configured.');
        }

        // The key PayMe dedupes on. Deterministic: a retry of this cycle sends the SAME
        // reference, so PayMe recognises it rather than booking a second sale.
        $transactionId = PaymentReference::for($idempotencyKey);

        try {
            $response = $this->client->generateSale([
                'price' => $amountAgorot,
                'buyerKey' => $reference,
                'transactionId' => $transactionId,
                'productName' => $opts['product_name'] ?? ' מנוי חודשי',
            ]);
        } catch (ConnectionException $e) {
            // The request never completed. The card may already be debited.
            return GatewayResult::ambiguous(
                'PayMe did not answer: '.$e->getMessage(),
                $this->ambiguousContext($transactionId, $e),
            );
        } catch (RequestException $e) {
            $status = $e->response?->status();

            // 5xx / 408 / 429: PayMe may have processed the sale and failed to tell us.
            // A 4xx business error is a definite decline and is safe to retry.
            if ($status === null || $status >= 500 || in_array($status, [408, 429], true)) {
                return GatewayResult::ambiguous(
                    "PayMe returned {$status} — the outcome is unknown.",
                    $this->ambiguousContext($transactionId, $e),
                );
            }

            return GatewayResult::failure(
                'http_'.$status,
                (string) $e->getMessage(),
                $this->client->getLastErrorContext() + ['payme_transaction_ref' => $transactionId],
            );
        } catch (Throwable $e) {
            // Anything we cannot classify is, by definition, unknown — not a decline.
            return GatewayResult::ambiguous(
                'PayMe call failed in an unknown way: '.$e->getMessage(),
                $this->ambiguousContext($transactionId, $e),
            );
        }

        $masked = PaymentContextMasker::mask([
            'payme_status_code' => $response['status_code'] ?? null,
            'payme_sale_status' => $response['sale_status'] ?? null,
            'payme_sale_id' => $response['payme_sale_id'] ?? ($response['sale_payme_id'] ?? null),
            'payme_transaction_ref' => $transactionId,
            'idempotency_key' => $idempotencyKey,
        ]);

        $statusCode = $response['status_code'] ?? 0;
        $saleId = (string) ($response['payme_sale_id'] ?? ($response['sale_payme_id'] ?? ''));

        if ((int) $statusCode === 0 && $saleId !== '') {
            return GatewayResult::success($saleId, $masked);
        }

        // PayMe answered, and the answer was no. Definite — safe to retry later.
        return GatewayResult::failure(
            (string) ($response['status_error_code'] ?? $statusCode ?? 'error'),
            (string) ($response['status_error_details'] ?? 'PayMe charge was not approved.'),
            $masked,
        );
    }

    /**
     * Ask PayMe what actually happened to a charge whose answer we lost.
     *
     * This is the only way out of an ambiguous state: the reference we sent is the one
     * PayMe knows the sale by, so we can go and look.
     */
    public function lookup(string $idempotencyKey): GatewayResult
    {
        $transactionId = PaymentReference::for($idempotencyKey);

        if (! $this->client->isConfigured()) {
            return GatewayResult::ambiguous('PayMe API is not configured.');
        }

        try {
            $response = $this->client->getTransaction($transactionId);
        } catch (Throwable $e) {
            // Still cannot tell. Stay ambiguous — never guess in this direction.
            return GatewayResult::ambiguous(
                'PayMe lookup failed: '.$e->getMessage(),
                ['payme_transaction_ref' => $transactionId],
            );
        }

        $masked = PaymentContextMasker::mask([
            'payme_lookup' => true,
            'payme_transaction_ref' => $transactionId,
            'payme_status_code' => $response['status_code'] ?? null,
            'payme_sale_status' => $response['sale_status'] ?? null,
        ]);

        $sale = $this->firstSale($response);

        if ($sale === null) {
            // PayMe has no record of it → the charge never happened. Safe to retry.
            return GatewayResult::failure('not_found', 'PayMe has no sale for this reference.', $masked);
        }

        $saleId = (string) ($sale['payme_sale_id'] ?? ($sale['sale_payme_id'] ?? ''));
        $saleStatus = strtolower((string) ($sale['sale_status'] ?? ''));

        if ($saleId !== '' && in_array($saleStatus, ['completed', 'success', 'paid', 'captured'], true)) {
            return GatewayResult::success($saleId, $masked);
        }

        if (in_array($saleStatus, ['failed', 'declined', 'refused', 'cancelled'], true)) {
            return GatewayResult::failure('declined', 'PayMe reports the sale was not approved.', $masked);
        }

        // Pending / initial / anything we do not recognise: still unknown. Do not guess.
        return GatewayResult::ambiguous("PayMe reports sale_status='{$saleStatus}'.", $masked);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>|null
     */
    private function firstSale(array $response): ?array
    {
        foreach (['sales', 'items', 'transactions', 'data'] as $key) {
            if (! empty($response[$key]) && is_array($response[$key])) {
                $first = reset($response[$key]);

                return is_array($first) ? $first : null;
            }
        }

        // Some responses return the sale at the top level.
        return isset($response['sale_status']) || isset($response['payme_sale_id']) ? $response : null;
    }

    /** @return array<string, mixed> */
    private function ambiguousContext(string $transactionId, Throwable $e): array
    {
        return $this->client->getLastErrorContext() + [
            'payme_transaction_ref' => $transactionId,
            'exception' => class_basename($e),
        ];
    }
}
