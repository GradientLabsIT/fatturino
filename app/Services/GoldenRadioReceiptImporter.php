<?php

namespace App\Services;

use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Enums\ReceiptTransmissionChannel;
use App\Models\Receipt;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class GoldenRadioReceiptImporter
{
    public function __construct(private ReceiptMutationService $mutation) {}

    /** @return array{created: int, skipped: int, receipts: array<int, Receipt>} */
    public function import(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Impossibile leggere il CSV.');
        }

        $header = fgetcsv($handle, 0, ';');
        $expected = ['Mese', 'N. vendite', 'Imponibile 22% (IT+UE)', 'IVA 22%', 'Fuori campo 7-octies (extra-UE)', 'UK lordo (da definire)'];
        if ($header !== $expected) {
            fclose($handle);
            throw new RuntimeException('Formato CSV Golden Radio non riconosciuto.');
        }

        $created = 0;
        $skipped = 0;
        $receipts = [];

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) < 6 || trim((string) $row[0]) === '' || strtoupper(trim((string) $row[0])) === 'TOTALE') {
                continue;
            }

            [$month, $count, $taxable22, $vat22, $outsideEu, $uk] = $row;
            if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
                throw new RuntimeException("Mese non valido nel CSV: {$month}");
            }

            $externalReference = "golden-radio:{$month}";
            if (Receipt::where('external_reference', $externalReference)->exists()) {
                $skipped++;

                continue;
            }

            $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
            $end = $start->endOfMonth();
            $taxable22Float = $this->number($taxable22);
            $vat22Float = $this->number($vat22);
            $outsideEuFloat = $this->number($outsideEu);
            $ukFloat = $this->number($uk);

            $lines = [];
            if ($taxable22Float !== 0.0 || $vat22Float !== 0.0) {
                $lines[] = [
                    'description' => 'Golden Radio - vendite B2C Italia e UE',
                    'quantity' => 1,
                    'unit_price_gross' => $taxable22Float + $vat22Float,
                    'vat_rate_code' => '22',
                    'country_group' => 'IT+UE',
                    'net_amount' => $taxable22Float,
                    'vat_amount' => $vat22Float,
                    'gross_amount' => $taxable22Float + $vat22Float,
                ];
            }
            if ($outsideEuFloat !== 0.0) {
                $lines[] = [
                    'description' => 'Golden Radio - vendite B2C extra-UE',
                    'quantity' => 1,
                    'unit_price_gross' => $outsideEuFloat,
                    'vat_rate_code' => 'N2.1',
                    'vat_nature' => 'Fuori campo art. 7-octies DPR 633/72',
                    'country_group' => 'extra-UE',
                    'net_amount' => $outsideEuFloat,
                    'vat_amount' => 0,
                    'gross_amount' => $outsideEuFloat,
                ];
            }
            if ($ukFloat !== 0.0) {
                $lines[] = [
                    'description' => 'Golden Radio - vendite B2C Regno Unito',
                    'quantity' => 1,
                    'unit_price_gross' => $ukFloat,
                    'vat_rate_code' => 'N2.1',
                    'vat_nature' => 'Trattamento IVA UK da confermare',
                    'country_group' => 'UK',
                    'net_amount' => $ukFloat,
                    'vat_amount' => 0,
                    'gross_amount' => $ukFloat,
                ];
            }

            $gross = $taxable22Float + $vat22Float + $outsideEuFloat + $ukFloat;
            $receipts[] = $this->mutation->create([
                'date' => $end->toDateString(),
                'kind' => ReceiptKind::MonthlySummary->value,
                'status' => ReceiptStatus::Recorded->value,
                'transmission_channel' => ReceiptTransmissionChannel::RegisterOnly->value,
                'description' => "Golden Radio - corrispettivi {$month}",
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'transaction_count' => (int) $count,
                'source' => 'golden_radio_csv',
                'external_reference' => $externalReference,
                'electronic_payment_amount' => $gross,
                'metadata' => ['import_format' => 'golden_radio_summary_v1'],
                'lines' => $lines,
            ]);
            $created++;
        }

        fclose($handle);

        return compact('created', 'skipped', 'receipts');
    }

    private function number(string $value): float
    {
        $value = str_replace(',', '.', trim($value));
        if (! is_numeric($value)) {
            throw new RuntimeException("Importo non valido nel CSV: {$value}");
        }

        return (float) $value;
    }
}
