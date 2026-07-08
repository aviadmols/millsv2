<?php

namespace App\Modules\MillsSubscriptions\Services\PayMe;

use App\Support\PaymentContextMasker;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin PayMe HTTP client — ported verbatim from the working v1 PaymeService
 * (generate-sale / get-transactions / get-buyer-key). Card data never passes
 * through here; charges use a saved buyer_key.
 */
class PaymeClient
{
    /** @var array<string, mixed> */
    private array $lastErrorContext = [];

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $sellerId,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('payme.api_url'), '/'),
            (string) config('payme.seller_id'),
        );
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->sellerId !== '';
    }

    /**
     * @param  array{price: int|float, transactionId?: string, buyerKey: string, productName?: string}  $payload
     * @return array<string, mixed>
     */
    public function generateSale(array $payload): array
    {
        $request = [
            'seller_payme_id' => $this->sellerId,
            'sale_price' => $payload['price'],           // agorot (ILS minor units)
            'currency' => 'ILS',
            'product_name' => $payload['productName'] ?? ' מנוי חודשי',
            'installments' => '1',
            'buyer_key' => $payload['buyerKey'],
        ];

        $transactionId = trim((string) ($payload['transactionId'] ?? ''));
        if ($transactionId !== '') {
            $request['transaction_id'] = $transactionId;
        }

        return $this->post('/generate-sale', $request);
    }

    /** @return array<string, mixed> */
    public function getTransaction(string $paymentId): array
    {
        return $this->post('/get-transactions', [
            'transaction_id' => $paymentId,
            'seller_payme_id' => $this->sellerId,
        ]);
    }

    /** @return array<string, mixed> */
    public function getBuyerKey(string $paymeSaleId): array
    {
        return $this->post('/get-buyer-key', [
            'seller_payme_id' => $this->sellerId,
            'payme_sale_id' => $paymeSaleId,
        ]);
    }

    /** @return array<string, mixed> */
    public function getLastErrorContext(): array
    {
        return $this->lastErrorContext;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PayMe API URL or seller ID is not configured.');
        }

        $this->lastErrorContext = [];

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(30)
                ->post($this->apiUrl.$path, $payload);
            $response->throw();
        } catch (RequestException $e) {
            $decoded = is_array($e->response?->json()) ? $e->response->json() : [];
            $this->lastErrorContext = PaymentContextMasker::mask([
                'payme_endpoint' => $path,
                'payme_http_status' => $e->response?->status(),
                'payme_status_code' => $decoded['status_code'] ?? null,
                'payme_status_error_code' => $decoded['status_error_code'] ?? null,
                'payme_status_error_details' => $decoded['status_error_details'] ?? null,
            ]);
            Log::error('payme.http_error', ['path' => $path, 'status' => $e->response?->status()]);
            throw $e;
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
