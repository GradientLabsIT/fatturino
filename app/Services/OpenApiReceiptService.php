<?php

namespace App\Services;

use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Enums\ReceiptTransmissionChannel;
use App\Models\Receipt;
use App\Settings\CompanySettings;
use App\Settings\OpenApiSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenApiReceiptService
{
    private string $baseUrl;

    private string $token;

    public function __construct(OpenApiSettings $settings)
    {
        $sandbox = (bool) $settings->sandbox;
        $this->baseUrl = rtrim($sandbox ? config('receipts.sandbox_url') : config('receipts.production_url'), '/');
        $this->token = (string) (config('receipts.api_token') ?: $settings->api_token);
    }

    /** @return array<string, mixed> */
    public function submit(Receipt $receipt): array
    {
        $receipt->loadMissing('lines');
        $this->assertSubmittable($receipt);

        $channel = $receipt->transmission_channel;
        $endpoint = $channel === ReceiptTransmissionChannel::ElectronicReceipt
            ? '/IT-e-receipts'
            : '/IT-receipts';
        $payload = $this->payload($receipt, $channel);

        $receipt->forceFill([
            'status' => ReceiptStatus::Pending,
            'provider' => 'openapi',
            'submitted_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        try {
            $response = $this->request()->post($this->baseUrl.$endpoint, $payload);
            $body = $response->json() ?: [];
            $data = $body['data'] ?? [];

            if (! $response->successful() || ! ($body['success'] ?? false)) {
                return $this->markFailed($receipt, $response, $body);
            }

            $receipt->forceFill([
                'provider_id' => $data['id'] ?? null,
                'provider_status' => $data['status'] ?? 'submitted',
                'provider_document_number' => $data['document_number'] ?? null,
                'provider_payload' => $data,
                'status' => $this->mapStatus((string) ($data['status'] ?? 'submitted')),
                'last_synced_at' => now(),
            ])->save();

            return ['success' => true, 'receipt' => $receipt->fresh('lines')];
        } catch (\Throwable $exception) {
            Log::error('OpenAPI receipt submission failed', ['receipt_id' => $receipt->id, 'error' => $exception->getMessage()]);
            $receipt->forceFill([
                'status' => ReceiptStatus::Failed,
                'error_message' => $exception->getMessage(),
            ])->save();

            return ['success' => false, 'error' => $exception->getMessage(), 'receipt' => $receipt];
        }
    }

    /** @return array<string, mixed> */
    public function sync(Receipt $receipt): array
    {
        if (! $receipt->provider_id) {
            throw new RuntimeException('Corrispettivo privo di identificativo OpenAPI.');
        }

        $endpoint = $receipt->transmission_channel === ReceiptTransmissionChannel::ElectronicReceipt
            ? '/IT-e-receipts/'
            : '/IT-receipts/';
        $response = $this->request()->get($this->baseUrl.$endpoint.$receipt->provider_id);
        $body = $response->json() ?: [];
        $data = $body['data'] ?? [];

        if (! $response->successful() || ! ($body['success'] ?? false)) {
            return $this->markFailed($receipt, $response, $body);
        }

        $receipt->forceFill([
            'provider_status' => $data['status'] ?? $receipt->provider_status,
            'provider_document_number' => $data['document_number'] ?? $receipt->provider_document_number,
            'provider_payload' => $data,
            'status' => $this->mapStatus((string) ($data['status'] ?? 'submitted')),
            'error_code' => $data['error_code'] ?? null,
            'error_message' => $data['error_message'] ?? null,
            'last_synced_at' => now(),
        ])->save();

        return ['success' => true, 'receipt' => $receipt->fresh('lines')];
    }

    private function assertSubmittable(Receipt $receipt): void
    {
        if ($this->token === '') {
            throw new RuntimeException('Token OpenAPI Invoice non configurato.');
        }
        if ($receipt->kind !== ReceiptKind::Individual) {
            throw new RuntimeException('Riepiloghi mensili: sola registrazione contabile, non trasmissibili come singolo documento commerciale.');
        }
        if ($receipt->transmission_channel === ReceiptTransmissionChannel::RegisterOnly) {
            throw new RuntimeException('Seleziona Smart Receipt o Corrispettivo elettronico prima della trasmissione.');
        }
        if ($receipt->provider_id) {
            throw new RuntimeException('Corrispettivo già trasmesso.');
        }
        if ($receipt->lines->isEmpty()) {
            throw new RuntimeException('Inserisci almeno una riga.');
        }
        if ($receipt->total_gross !== ($receipt->cash_payment_amount + $receipt->electronic_payment_amount + $receipt->uncollected_amount)) {
            throw new RuntimeException('Totale pagamenti diverso dal totale documento.');
        }
    }

    /** @return array<string, mixed> */
    private function payload(Receipt $receipt, ReceiptTransmissionChannel $channel): array
    {
        $settings = app(CompanySettings::class);
        $fiscalId = preg_replace('/^[A-Z]{2}/i', '', $settings->company_vat_number);
        if (! preg_match('/^\d{11}$/', $fiscalId)) {
            throw new RuntimeException('Partita IVA aziendale non valida per OpenAPI Corrispettivi.');
        }

        $items = $receipt->lines->values()->map(function ($line, int $index) use ($channel) {
            $vatCode = $this->providerVatCode($line->vat_rate_code, $channel);
            $item = [
                'quantity' => number_format((float) $line->quantity, 2, '.', ''),
                'description' => $line->description,
                'unit_price' => $this->euros($line->unit_price_gross),
                'vat_rate_code' => $vatCode,
            ];

            if ($channel === ReceiptTransmissionChannel::ElectronicReceipt) {
                $item['id'] = $index + 1;
                $item['type'] = $line->item_type === 'goods' ? 'goods' : 'service';
                $item['discount'] = '0.00';
                $item['surcharge'] = '0.00';
            } else {
                $item['quantity'] = (float) $item['quantity'];
                $item['unit_price'] = (float) $item['unit_price'];
            }

            return $item;
        })->all();

        $payload = [
            'fiscal_id' => $fiscalId,
            'items' => $items,
            'cash_payment_amount' => $this->paymentValue($receipt->cash_payment_amount, $channel),
            'electronic_payment_amount' => $this->paymentValue($receipt->electronic_payment_amount, $channel),
            'services_uncollected_amount' => $this->paymentValue($receipt->uncollected_amount, $channel),
            'invoice_issuing' => false,
        ];

        if ($channel === ReceiptTransmissionChannel::ElectronicReceipt) {
            $payload['type'] = 'sale';
            $payload['additional_text'] = $receipt->description;
            foreach (['store_id', 'cash_register_id', 'cashier_id'] as $configKey) {
                $value = config("receipts.{$configKey}");
                if ($value) {
                    $payload[$configKey === 'cash_register_id' ? 'cr_id' : $configKey] = $value;
                }
            }
        } else {
            $payload['tags'] = array_values(array_filter(['fatturino', $receipt->external_reference]));
        }

        return $payload;
    }

    private function providerVatCode(string $code, ReceiptTransmissionChannel $channel): string
    {
        if (preg_match('/^N[1-6]/', $code, $matches)) {
            return $matches[0];
        }

        $number = number_format((float) $code, 2, '.', '');

        return $channel === ReceiptTransmissionChannel::ElectronicReceipt
            ? $number
            : rtrim(rtrim($number, '0'), '.');
    }

    private function paymentValue(int $cents, ReceiptTransmissionChannel $channel): string|float
    {
        $value = $this->euros($cents);

        return $channel === ReceiptTransmissionChannel::ElectronicReceipt ? $value : (float) $value;
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()->asJson()->withToken($this->token)->connectTimeout(5)->timeout(25)->retry(2, 250, throw: false);
    }

    /** @param array<string, mixed> $body */
    private function markFailed(Receipt $receipt, Response $response, array $body): array
    {
        $message = (string) ($body['message'] ?? $body['error'] ?? "HTTP {$response->status()}");
        $receipt->forceFill([
            'status' => ReceiptStatus::Failed,
            'provider_status' => $body['data']['status'] ?? 'failed',
            'provider_payload' => $body['data'] ?? $body,
            'error_code' => (string) ($body['error'] ?? $response->status()),
            'error_message' => $message,
            'last_synced_at' => now(),
        ])->save();

        return ['success' => false, 'error' => $message, 'receipt' => $receipt];
    }

    private function mapStatus(string $status): ReceiptStatus
    {
        return match (strtolower($status)) {
            'ready', 'sent' => ReceiptStatus::Ready,
            'failed', 'error' => ReceiptStatus::Failed,
            'voided', 'void' => ReceiptStatus::Voided,
            default => ReceiptStatus::Pending,
        };
    }
}
