<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvoiceStatus;
use App\Enums\VatRate;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\PurchaseInvoice;
use App\Services\DocumentEventRecorder;
use App\Services\DocumentStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseInvoiceCreateController extends Controller
{
    public function store(Request $request, DocumentStorageService $storage): JsonResponse
    {
        $validated = $request->validate([
            'contact_id' => 'nullable|required_without:supplier|exists:contacts,id',
            'supplier' => 'nullable|required_without:contact_id|array',
            'supplier.name' => 'required_with:supplier|string|max:255',
            'supplier.vat_number' => 'nullable|string|max:50',
            'supplier.tax_code' => 'nullable|string|max:50',
            'supplier.address' => 'nullable|string|max:255',
            'supplier.city' => 'nullable|string|max:100',
            'supplier.postal_code' => 'nullable|string|max:20',
            'supplier.province' => 'nullable|string|max:10',
            'supplier.country' => 'nullable|string|size:2',
            'supplier.country_code' => 'nullable|string|size:2',
            'supplier.sdi_code' => 'nullable|string|max:7',
            'supplier.pec' => 'nullable|email|max:255',
            'supplier.email' => 'nullable|email|max:255',
            'supplier.phone' => 'nullable|string|max:50',
            'invoice_number' => 'required|string|max:255',
            'date' => 'required|date',
            'due_date' => 'nullable|date',
            'document_type' => 'nullable|string|max:10',
            'notes' => 'nullable|string',
            'external_source' => 'nullable|required_with:external_id|string|max:100',
            'external_id' => 'nullable|string|max:255',
            'withholding_tax_enabled' => 'boolean',
            'withholding_tax_percent' => 'nullable|numeric|min:0|max:100|required_if:withholding_tax_enabled,true',
            'payment' => 'nullable|array',
            'payment.amount' => 'required_with:payment|numeric|min:0.01',
            'payment.paid_at' => 'required_with:payment|date',
            'payment.payment_method' => 'nullable|string|max:20',
            'payment.reference' => 'nullable|string|max:255',
            'payment.notes' => 'nullable|string',
            'payment.bank_name' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1',
            'lines.*.description' => 'required|string',
            'lines.*.quantity' => 'required|numeric|min:0.01',
            'lines.*.unit_of_measure' => 'nullable|string|max:10',
            'lines.*.unit_price' => 'required|numeric|min:0',
            'lines.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'lines.*.vat_rate' => ['required', 'string', Rule::enum(VatRate::class)],
            'attachment' => 'nullable|file|mimes:pdf|max:20480',
        ]);

        $year = (int) substr($validated['date'], 0, 4);
        $externalSource = $validated['external_source'] ?? null;
        $externalId = $validated['external_id'] ?? null;
        $existing = $this->findExisting($year, $validated['invoice_number'], $externalSource, $externalId);

        if ($existing) {
            return response()->json($this->responseData($existing, false));
        }

        $invoice = DB::transaction(function () use ($validated, $year, $externalSource, $externalId): PurchaseInvoice {
            $contact = isset($validated['contact_id'])
                ? Contact::query()->findOrFail($validated['contact_id'])
                : $this->resolveSupplier($validated['supplier']);

            $invoice = PurchaseInvoice::query()->create([
                'number' => $validated['invoice_number'],
                'date' => $validated['date'],
                'due_date' => $validated['due_date'] ?? null,
                'fiscal_year' => $year,
                'contact_id' => $contact->id,
                'status' => InvoiceStatus::Generated,
                'document_type' => $validated['document_type'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'source' => 'api',
                'external_source' => $externalSource,
                'external_id' => $externalId,
                'withholding_tax_enabled' => $validated['withholding_tax_enabled'] ?? false,
                'withholding_tax_percent' => $validated['withholding_tax_percent'] ?? null,
            ]);

            foreach ($validated['lines'] as $line) {
                $invoice->lines()->create($this->buildLinePayload($line));
            }
            $invoice->calculateTotals();

            if (isset($validated['payment'])) {
                $payment = $validated['payment'];
                $invoice->payments()->create([
                    'amount' => (int) round((float) $payment['amount'] * 100),
                    'paid_at' => $payment['paid_at'],
                    'payment_method' => $payment['payment_method'] ?? null,
                    'reference' => $payment['reference'] ?? null,
                    'notes' => $payment['notes'] ?? null,
                    'bank_name' => $payment['bank_name'] ?? null,
                ]);
                $invoice->recalculatePaymentStatus();
            }

            app(DocumentEventRecorder::class)->created($invoice);

            return $invoice->fresh(['contact', 'lines', 'payments']);
        });

        if ($request->hasFile('attachment')) {
            $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', $validated['invoice_number']).'.pdf';
            $path = $storage->storePdf(
                $request->file('attachment')->getContent(),
                'purchase',
                $year,
                $filename,
            );
            $invoice->update(['pdf_path' => $path]);
        }

        return response()->json($this->responseData($invoice->fresh(), true), 201);
    }

    private function findExisting(int $year, string $number, ?string $externalSource, ?string $externalId): ?PurchaseInvoice
    {
        if ($externalSource && $externalId) {
            $invoice = PurchaseInvoice::query()
                ->where('external_source', $externalSource)
                ->where('external_id', $externalId)
                ->first();
            if ($invoice) {
                return $invoice;
            }
        }

        return PurchaseInvoice::query()
            ->where('fiscal_year', $year)
            ->where('number', $number)
            ->first();
    }

    private function resolveSupplier(array $supplier): Contact
    {
        $query = Contact::query();
        if (! empty($supplier['vat_number'])) {
            $query->where('vat_number', $supplier['vat_number']);
        } else {
            $query->where('name', $supplier['name']);
        }

        $contact = $query->first();
        if ($contact) {
            return $contact;
        }

        return Contact::query()->create($supplier);
    }

    private function buildLinePayload(array $line): array
    {
        $quantity = (float) $line['quantity'];
        $unitPrice = (float) $line['unit_price'];
        $gross = $quantity * $unitPrice;
        $discountPercent = isset($line['discount_percent']) ? (float) $line['discount_percent'] : null;
        $discountedTotal = $discountPercent
            ? $gross * (1 - $discountPercent / 100)
            : $gross;

        return [
            'description' => $line['description'],
            'quantity' => $quantity,
            'unit_of_measure' => ($line['unit_of_measure'] ?? null) ?: null,
            'unit_price' => (int) round($unitPrice * 100),
            'discount_percent' => $discountPercent,
            'discount_amount' => $discountPercent ? (int) round(($gross - $discountedTotal) * 100) : null,
            'vat_rate' => $line['vat_rate'],
            'total' => (int) round($discountedTotal * 100),
        ];
    }

    private function responseData(PurchaseInvoice $invoice, bool $created): array
    {
        return [
            'message' => $created ? 'Costo creato.' : 'Costo già presente.',
            'created' => $created,
            'purchase_invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
        ];
    }
}
