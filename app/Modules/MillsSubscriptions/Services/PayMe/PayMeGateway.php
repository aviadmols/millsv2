<?php

namespace App\Modules\MillsSubscriptions\Services\PayMe;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayResult;
use App\Support\PaymentContextMasker;
use Throwable;

/**
 * PayMe implementation of the gateway contract. Charges a saved buyer_key via
 * PaymeClient::generateSale and normalizes the response to a GatewayResult. The
 * raw response is masked before it leaves this class.
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

        try {
            $response = $this->client->generateSale([
                'price' => $amountAgorot,
                'buyerKey' => $reference,
                'transactionId' => (string) ($opts['transaction_id'] ?? ''),
                'productName' => $opts['product_name'] ?? ' מנוי חודשי',
            ]);

            $masked = PaymentContextMasker::mask([
                'payme_status_code' => $response['status_code'] ?? null,
                'payme_sale_status' => $response['sale_status'] ?? null,
                'payme_sale_id' => $response['payme_sale_id'] ?? ($response['sale_payme_id'] ?? null),
                'idempotency_key' => $idempotencyKey,
            ]);

            $statusCode = $response['status_code'] ?? 0;
            $saleId = (string) ($response['payme_sale_id'] ?? ($response['sale_payme_id'] ?? ''));

            if ((int) $statusCode === 0 && $saleId !== '') {
                return GatewayResult::success($saleId, $masked);
            }

            return GatewayResult::failure(
                (string) ($response['status_error_code'] ?? $statusCode ?? 'error'),
                (string) ($response['status_error_details'] ?? 'PayMe charge was not approved.'),
                $masked,
            );
        } catch (Throwable $e) {
            return GatewayResult::failure('exception', $e->getMessage(), $this->client->getLastErrorContext());
        }
    }
}
