<?php

namespace App\Services;

use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Enums\ReceiptTransmissionChannel;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReceiptMutationService
{
    /** @param array<string, mixed> $data */
    public function create(array $data): Receipt
    {
        return DB::transaction(function () use ($data) {
            $receipt = Receipt::create($this->receiptAttributes($data));
            $this->replaceLines($receipt, $data['lines'] ?? []);

            return $receipt->fresh('lines');
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Receipt $receipt, array $data): Receipt
    {
        if (! $receipt->isEditable()) {
            throw new InvalidArgumentException('Corrispettivo trasmesso o annullato: modifica non consentita.');
        }

        return DB::transaction(function () use ($receipt, $data) {
            $receipt->update($this->receiptAttributes($data));
            $this->replaceLines($receipt, $data['lines'] ?? []);

            return $receipt->fresh('lines');
        });
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function replaceLines(Receipt $receipt, array $lines): void
    {
        $receipt->lines()->delete();

        foreach ($lines as $line) {
            $quantity = (string) ($line['quantity'] ?? '1');
            $gross = $this->cents($line['gross_amount'] ?? 0);
            $unitGross = array_key_exists('unit_price_gross', $line)
                ? $this->cents($line['unit_price_gross'])
                : (int) round($gross / max((float) $quantity, 0.01));

            $receipt->lines()->create([
                'item_type' => $line['item_type'] ?? 'service',
                'description' => trim((string) $line['description']),
                'quantity' => $quantity,
                'unit_price_gross' => $unitGross,
                'vat_rate_code' => (string) $line['vat_rate_code'],
                'vat_nature' => $line['vat_nature'] ?? null,
                'country_group' => $line['country_group'] ?? null,
                'net_amount' => $this->cents($line['net_amount'] ?? 0),
                'vat_amount' => $this->cents($line['vat_amount'] ?? 0),
                'gross_amount' => $gross,
                'metadata' => $line['metadata'] ?? null,
            ]);
        }

        $receipt->recalculateTotals();
    }

    /** @param array<string, mixed> $data */
    private function receiptAttributes(array $data): array
    {
        $date = (string) $data['date'];

        return [
            'date' => $date,
            'fiscal_year' => (int) substr($date, 0, 4),
            'kind' => ReceiptKind::from($data['kind'] ?? ReceiptKind::MonthlySummary->value),
            'status' => ReceiptStatus::from($data['status'] ?? ReceiptStatus::Draft->value),
            'transmission_channel' => ReceiptTransmissionChannel::from($data['transmission_channel'] ?? ReceiptTransmissionChannel::RegisterOnly->value),
            'description' => trim((string) $data['description']),
            'period_start' => $data['period_start'] ?? null,
            'period_end' => $data['period_end'] ?? null,
            'transaction_count' => (int) ($data['transaction_count'] ?? 1),
            'source' => $data['source'] ?? 'manual',
            'external_reference' => $data['external_reference'] ?? null,
            'cash_payment_amount' => $this->cents($data['cash_payment_amount'] ?? 0),
            'electronic_payment_amount' => $this->cents($data['electronic_payment_amount'] ?? 0),
            'uncollected_amount' => $this->cents($data['uncollected_amount'] ?? 0),
            'metadata' => $data['metadata'] ?? null,
        ];
    }

    private function cents(mixed $amount): int
    {
        $normalized = str_replace(',', '.', trim((string) $amount));
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $normalized, $matches)) {
            throw new InvalidArgumentException("Importo non valido: {$amount}");
        }

        $fraction = str_pad($matches[3] ?? '', 3, '0');
        $cents = ((int) $matches[2] * 100) + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return ($matches[1] ?? '') === '-' ? -$cents : $cents;
    }
}
