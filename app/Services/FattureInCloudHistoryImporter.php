<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\SdiStatus;
use App\Enums\VatRate;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\Payment;
use App\Models\PurchaseInvoice;
use App\Models\SalesInvoice;
use App\Models\SelfInvoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FattureInCloudHistoryImporter
{
    private const SOURCE = 'fatture_in_cloud';

    private PendingRequest $api;

    private string $baseUrl;

    private array $stats = [];

    public function __construct(private readonly DocumentStorageService $storage) {}

    /**
     * Import historical Fatture in Cloud documents. This class only performs GET requests.
     * It never calls an SDI or Fatture in Cloud send endpoint.
     *
     * @return array<string, int>
     */
    public function importYear(string $token, int $companyId, int $year, bool $downloadFiles = true, bool $dryRun = false): array
    {
        $this->stats = [
            'found' => 0,
            'created' => 0,
            'skipped' => 0,
            'contacts_created' => 0,
            'pdf_downloaded' => 0,
            'xml_downloaded' => 0,
            'file_errors' => 0,
        ];
        $this->baseUrl = "https://api-v2.fattureincloud.it/c/{$companyId}";
        $this->api = Http::withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->retry(5, 1500, throw: false);

        $issued = [];
        foreach (['invoice', 'credit_note', 'self_supplier_invoice'] as $type) {
            $issued = [...$issued, ...$this->list('/issued_documents', [
                'type' => $type,
                'fieldset' => 'detailed',
            ])];
        }

        $received = $this->list('/received_documents', [
            'type' => 'expense',
            'fieldset' => 'detailed',
        ]);

        $issued = array_values(array_filter($issued, fn (array $document): bool => (int) ($document['year'] ?? substr((string) $document['date'], 0, 4)) === $year));
        $received = array_values(array_filter($received, fn (array $document): bool => (int) substr((string) $document['date'], 0, 4) === $year));
        $this->stats['found'] = count($issued) + count($received);

        if ($dryRun) {
            return $this->stats;
        }

        foreach ($issued as $document) {
            $this->importDocument($document, true, $downloadFiles);
        }

        foreach ($received as $document) {
            $this->importDocument($document, false, $downloadFiles);
        }

        return $this->stats;
    }

    /** @return array<int, array<string, mixed>> */
    private function list(string $path, array $parameters): array
    {
        $documents = [];

        for ($page = 1; ; $page++) {
            $response = $this->api->get($this->baseUrl.$path, [
                ...$parameters,
                'per_page' => 100,
                'page' => $page,
            ]);
            $response->throw();
            $batch = $response->json('data') ?? [];
            $documents = [...$documents, ...$batch];

            if (count($batch) < 100) {
                break;
            }
        }

        return $documents;
    }

    private function importDocument(array $source, bool $issued, bool $downloadFiles): void
    {
        $externalId = (string) $source['id'];
        $existing = FiscalDocument::query()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first();

        if ($existing) {
            if ($downloadFiles) {
                $this->fillMissingFiles($existing, $source, $issued);
            }
            $this->stats['skipped']++;

            return;
        }

        $contact = $this->contact($source['entity'] ?? [], $issued ? (string) $source['type'] : 'expense');
        $document = DB::transaction(function () use ($source, $issued, $externalId, $contact): FiscalDocument {
            $document = $this->createDocument($source, $issued, $externalId, $contact);
            $this->createLines($document, $source, $issued);
            $this->createPayments($document, $source, $issued);
            $this->applyExactTotals($document, $source);

            return $document->fresh();
        });

        if ($downloadFiles) {
            $this->fillMissingFiles($document, $source, $issued);
        }

        $this->stats['created']++;
    }

    private function createDocument(array $source, bool $issued, string $externalId, Contact $contact): FiscalDocument
    {
        $sourceType = $issued ? (string) $source['type'] : 'expense';
        $modelClass = match ($sourceType) {
            'invoice' => SalesInvoice::class,
            'credit_note' => CreditNote::class,
            'self_supplier_invoice' => SelfInvoice::class,
            default => PurchaseInvoice::class,
        };
        $type = match ($sourceType) {
            'invoice' => 'sales',
            'credit_note' => 'credit_note',
            'self_supplier_invoice' => 'self_invoice',
            default => 'purchase',
        };
        $sent = $issued && ($source['ei_status'] ?? null) !== 'not_sent';
        $date = (string) $source['date'];
        $payments = $source['payments_list'] ?? [];
        $dueDate = collect($payments)->pluck('due_date')->filter()->sort()->first() ?: ($source['next_due_date'] ?? null);
        $paymentMethod = data_get($source, 'ei_data.payment_method');
        $documentType = data_get($source, 'ei_raw.FatturaElettronicaBody.DatiGenerali.DatiGeneraliDocumento.TipoDocumento');

        if (! $documentType) {
            $documentType = match ($type) {
                'credit_note' => 'TD04',
                'self_invoice' => 'TD17',
                default => $issued ? 'TD01' : null,
            };
        }

        $number = $issued
            ? (string) $source['number'].(string) ($source['numeration'] ?? '')
            : (string) ($source['invoice_number'] ?: "FIC-{$externalId}");
        $notes = $issued
            ? trim(implode("\n\n", array_filter([$source['visible_subject'] ?? null, $source['notes'] ?? null])))
            : trim((string) ($source['description'] ?? ''));

        /** @var FiscalDocument $document */
        $document = $modelClass::query()->create([
            'type' => $type,
            'document_type' => $documentType,
            'number' => $number,
            'date' => $date,
            'fiscal_year' => (int) substr($date, 0, 4),
            'contact_id' => $contact->id,
            'related_invoice_number' => data_get($source, 'ei_data.od_number') ?: null,
            'related_invoice_date' => data_get($source, 'ei_data.od_date') ?: null,
            'status' => $issued ? ($sent ? InvoiceStatus::Sent : InvoiceStatus::Draft) : InvoiceStatus::Generated,
            'payment_status' => PaymentStatus::Unpaid,
            'due_date' => $dueDate,
            'sdi_id' => $sent ? "fic-{$externalId}" : null,
            'sdi_status' => $sent ? SdiStatus::Sent : (($source['e_invoice'] ?? false) && ! $issued ? SdiStatus::Received : null),
            'sdi_message' => $sent ? 'Storico importato da Fatture in Cloud; già inviato' : null,
            'sdi_sent_at' => $sent ? ($source['updated_at'] ?? $date) : null,
            'source' => 'fic_api',
            'external_source' => self::SOURCE,
            'external_id' => $externalId,
            'notes' => $notes ?: null,
            'withholding_tax_enabled' => $this->cents($source['amount_withholding_tax'] ?? 0) > 0,
            'withholding_tax_amount' => $this->cents($source['amount_withholding_tax'] ?? 0),
            'payment_method' => $paymentMethod,
            'payment_terms' => 'TP02',
            'bank_name' => data_get($source, 'ei_data.bank_name') ?: null,
            'bank_iban' => data_get($source, 'ei_data.bank_iban') ?: null,
            'vat_payability' => data_get($source, 'ei_data.vat_kind') ?: 'I',
            'split_payment' => (bool) ($source['use_split_payment'] ?? false),
            'stamp_duty_applied' => $this->cents($source['stamp_duty'] ?? 0) > 0,
            'stamp_duty_amount' => $this->cents($source['stamp_duty'] ?? 0),
            'metadata' => [
                'fic_document_id' => (int) $source['id'],
                'fic_document_type' => $sourceType,
                'fic_numeration' => $source['numeration'] ?? null,
                'fic_ei_status' => $source['ei_status'] ?? null,
                'fic_locked' => $source['locked'] ?? null,
                'fic_currency' => data_get($source, 'currency.id'),
                'fic_category' => $source['category'] ?? null,
                'fic_tax_deductibility' => $source['tax_deductibility'] ?? null,
                'fic_vat_deductibility' => $source['vat_deductibility'] ?? null,
                'fic_created_at' => $source['created_at'] ?? null,
                'fic_updated_at' => $source['updated_at'] ?? null,
            ],
        ]);

        return $document;
    }

    private function createLines(FiscalDocument $document, array $source, bool $issued): void
    {
        $items = $source['items_list'] ?? [];
        if (! $items) {
            $items = [[
                'name' => $source['category'] ?? 'Costo',
                'description' => $source['description'] ?? null,
                'qty' => 1,
                'net_price' => $source['amount_net'] ?? 0,
                'discount' => 0,
                'vat' => ['value' => $this->inferredVatPercent($source)],
            ]];
        }

        foreach ($items as $item) {
            $quantity = (float) ($item['qty'] ?? 1);
            $quantity = $quantity > 0 ? $quantity : 1;
            $unitPrice = $this->cents($item['net_price'] ?? 0);
            $discountPercent = (float) ($item['discount'] ?? 0);
            $discountAmount = (int) round($unitPrice * $quantity * ($discountPercent / 100));
            $total = max(0, (int) round($unitPrice * $quantity) - $discountAmount);
            $description = trim(implode(' — ', array_filter([
                $item['name'] ?? null,
                $item['description'] ?? null,
            ])));

            FiscalDocumentLine::query()->create([
                'fiscal_document_id' => $document->id,
                'description' => $description ?: ($issued ? 'Documento FIC' : 'Costo FIC'),
                'quantity' => $quantity,
                'unit_of_measure' => $item['measure'] ?? null,
                'unit_price' => $unitPrice,
                'discount_percent' => $discountPercent ?: null,
                'discount_amount' => $discountAmount ?: null,
                'vat_rate' => $this->vatRate($item['vat'] ?? []),
                'total' => $total,
            ]);
        }
    }

    private function createPayments(FiscalDocument $document, array $source, bool $issued): void
    {
        foreach ($source['payments_list'] ?? [] as $payment) {
            $status = (string) ($payment['status'] ?? '');
            $isSettled = $status === 'paid' || ($document->type === 'self_invoice' && $status === 'reversed');
            if (! $isSettled) {
                continue;
            }

            Payment::query()->create([
                'fiscal_document_id' => $document->id,
                'amount' => $this->cents($payment['amount'] ?? 0),
                'paid_at' => $payment['paid_date'] ?? ($issued && $document->type === 'self_invoice' ? $document->date : null),
                'payment_method' => data_get($source, 'ei_data.payment_method'),
                'reference' => isset($payment['id']) ? 'FIC '.$payment['id'] : null,
                'notes' => 'Pagamento importato da Fatture in Cloud',
            ]);
        }
    }

    private function applyExactTotals(FiscalDocument $document, array $source): void
    {
        $totalPaid = (int) $document->payments()->sum('amount');
        $gross = $this->cents($source['amount_gross'] ?? 0);
        $status = match (true) {
            $totalPaid <= 0 => PaymentStatus::Unpaid,
            $totalPaid < $gross => PaymentStatus::Partial,
            default => PaymentStatus::Paid,
        };

        $document->forceFill([
            'total_net' => $this->cents($source['amount_net'] ?? 0),
            'total_vat' => $this->cents($source['amount_vat'] ?? 0),
            'total_gross' => $gross,
            'total_paid' => $totalPaid,
            'payment_status' => $status,
        ])->save();
    }

    private function contact(array $source, string $documentType): Contact
    {
        $externalId = isset($source['id']) ? (string) $source['id'] : null;
        $contact = $externalId ? Contact::query()
            ->where('external_source', self::SOURCE)
            ->where('external_id', $externalId)
            ->first() : null;

        if (! $contact) {
            $vatNumber = trim((string) ($source['vat_number'] ?? '')) ?: null;
            $contact = Contact::query()
                ->where('name', (string) ($source['name'] ?? 'Contatto FIC'))
                ->when($vatNumber, fn ($query) => $query->where('vat_number', $vatNumber))
                ->first();
        }

        $isCustomer = in_array($documentType, ['invoice', 'credit_note'], true);
        $countryCode = $this->countryCode((string) ($source['country'] ?? ''), (string) ($source['vat_number'] ?? ''));
        $attributes = [
            'is_customer' => $isCustomer || (bool) ($contact?->is_customer),
            'is_supplier' => ! $isCustomer || (bool) ($contact?->is_supplier),
            'name' => (string) ($source['name'] ?? 'Contatto FIC'),
            'vat_number' => trim((string) ($source['vat_number'] ?? '')) ?: null,
            'tax_code' => trim((string) ($source['tax_code'] ?? '')) ?: null,
            'address' => trim(implode(', ', array_filter([$source['address_street'] ?? null, $source['address_extra'] ?? null]))) ?: null,
            'city' => $source['address_city'] ?? null,
            'postal_code' => $source['address_postal_code'] ?? null,
            'province' => $source['address_province'] ?? null,
            'country' => $countryCode,
            'country_code' => $countryCode,
            'sdi_code' => $source['ei_code'] ?? null,
            'pec' => $source['certified_email'] ?? null,
            'email' => $source['email'] ?? null,
            'phone' => $source['phone'] ?? null,
            'external_source' => $externalId ? self::SOURCE : null,
            'external_id' => $externalId,
        ];

        if ($contact) {
            $contact->update($attributes);

            return $contact;
        }

        $this->stats['contacts_created']++;

        return Contact::query()->create($attributes);
    }

    private function fillMissingFiles(FiscalDocument $document, array $source, bool $issued): void
    {
        $category = match ($document->type) {
            'sales' => 'sales',
            'credit_note' => 'credit-notes',
            'self_invoice' => 'self-invoices',
            default => 'purchase',
        };
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', $document->number) ?: 'fic-'.$source['id'];

        if (! $document->pdf_path) {
            $pdfUrl = $issued ? ($source['url'] ?? null) : ($source['attachment_url'] ?? data_get($source, 'attachments.0.download_url'));
            if ($pdfUrl) {
                try {
                    $response = Http::timeout(90)->retry(4, 1500, throw: false)->get($pdfUrl);
                    $response->throw();
                    $path = $this->storage->storePdf($response->body(), $category, (int) $document->fiscal_year, $filename.'.pdf');
                    $document->update(['pdf_path' => $path]);
                    $this->stats['pdf_downloaded']++;
                } catch (\Throwable) {
                    $this->stats['file_errors']++;
                }
            }
        }

        if ($issued && ! $document->xml_path && ($source['e_invoice'] ?? false)) {
            try {
                $response = $this->api->withHeaders(['Accept' => 'text/xml'])
                    ->get($this->baseUrl."/issued_documents/{$source['id']}/e_invoice/xml");
                $response->throw();
                $xml = $response->body();
                $path = $this->storage->storeXml($xml, $category, (int) $document->fiscal_year, $filename.'.xml');
                $document->update(['xml_path' => $path, 'sdi_raw_xml' => $xml]);
                $this->stats['xml_downloaded']++;
            } catch (\Throwable) {
                $this->stats['file_errors']++;
            }
        }
    }

    private function vatRate(array $vat): VatRate
    {
        $value = (float) ($vat['value'] ?? 0);
        foreach ([22 => VatRate::R22, 10 => VatRate::R10, 5 => VatRate::R5, 4 => VatRate::R4] as $percent => $rate) {
            if (abs($value - $percent) < 0.01) {
                return $rate;
            }
        }

        $description = mb_strtolower((string) ($vat['description'] ?? ''));

        return match (true) {
            str_contains($description, 'esente'), str_contains($description, 'art.10') => VatRate::N4,
            str_contains($description, 'art.7 ter'), str_contains($description, 'art. 7-ter') => VatRate::N2_1,
            str_contains($description, 'inversione contabile') => VatRate::N6_9,
            default => VatRate::N2_2,
        };
    }

    private function inferredVatPercent(array $source): int
    {
        $net = $this->cents($source['amount_net'] ?? 0);
        $vat = $this->cents($source['amount_vat'] ?? 0);
        foreach ([22, 10, 5, 4] as $percent) {
            if (abs($vat - (int) round($net * $percent / 100)) <= 1) {
                return $percent;
            }
        }

        return 0;
    }

    private function countryCode(string $country, string $vatNumber): string
    {
        $codes = [
            'Italia' => 'IT', 'Stati Uniti' => 'US', 'Germania' => 'DE', 'Irlanda' => 'IE',
            'Regno Unito' => 'GB', 'Paesi Bassi' => 'NL', 'Singapore' => 'SG', 'Lituania' => 'LT',
            'Australia' => 'AU', 'Canada' => 'CA', 'Francia' => 'FR', 'Sudafrica' => 'ZA',
            'Spagna' => 'ES', 'Norvegia' => 'NO', 'Danimarca' => 'DK', 'Brasile' => 'BR',
            'Porto Rico' => 'PR', 'Portogallo' => 'PT',
        ];
        if (isset($codes[$country])) {
            return $codes[$country];
        }
        if (preg_match('/^[A-Z]{2}/', $vatNumber, $matches)) {
            return $matches[0];
        }

        return strlen($country) === 2 ? strtoupper($country) : 'IT';
    }

    private function cents(mixed $amount): int
    {
        return max(0, (int) round((float) $amount * 100));
    }
}
