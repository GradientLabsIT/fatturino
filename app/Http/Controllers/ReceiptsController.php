<?php

namespace App\Http\Controllers;

use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Enums\ReceiptTransmissionChannel;
use App\Models\Receipt;
use App\Services\GoldenRadioReceiptImporter;
use App\Services\OpenApiReceiptService;
use App\Services\ReceiptMutationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptsController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) ($request->query('fiscal_year', session('fiscal_year', now()->year)));
        $status = (string) $request->query('status', '');
        $search = trim((string) $request->query('search', ''));

        $query = Receipt::query()->with('lines')->where('fiscal_year', $year);
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(fn ($q) => $q
                ->where('description', 'like', "%{$search}%")
                ->orWhere('external_reference', 'like', "%{$search}%")
                ->orWhere('provider_document_number', 'like', "%{$search}%"));
        }

        $statsQuery = Receipt::query()->where('fiscal_year', $year);
        $stats = [
            'count' => (clone $statsQuery)->count(),
            'transactions' => (int) (clone $statsQuery)->sum('transaction_count'),
            'net' => (int) (clone $statsQuery)->sum('total_net'),
            'vat' => (int) (clone $statsQuery)->sum('total_vat'),
            'gross' => (int) (clone $statsQuery)->sum('total_gross'),
        ];

        return Inertia::render('Receipts/Index', [
            'receipts' => $query->latest('date')->latest('id')->paginate(20)->withQueryString(),
            'stats' => $stats,
            'search' => $search,
            'filterStatus' => $status,
            'statusOptions' => $this->statusOptions(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Receipts/Create', ['formData' => $this->formData()]);
    }

    public function store(Request $request, ReceiptMutationService $mutation): RedirectResponse
    {
        $receipt = $mutation->create($this->validated($request));

        return redirect()->route('receipts.edit', $receipt)->with('toast', [
            'type' => 'success',
            'message' => 'Corrispettivo creato.',
        ]);
    }

    public function edit(Receipt $receipt): Response
    {
        $receipt->load('lines');

        return Inertia::render('Receipts/Edit', [
            'receipt' => $receipt,
            'initial' => $this->forForm($receipt),
            'formData' => $this->formData(),
        ]);
    }

    public function update(Request $request, Receipt $receipt, ReceiptMutationService $mutation): RedirectResponse
    {
        try {
            $mutation->update($receipt, $this->validated($request));
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['receipt' => $exception->getMessage()]);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Corrispettivo aggiornato.']);
    }

    public function destroy(Receipt $receipt): RedirectResponse
    {
        if (! $receipt->isEditable()) {
            return back()->withErrors(['receipt' => 'Documento trasmesso: eliminazione non consentita.']);
        }

        $receipt->delete();

        return redirect()->route('receipts.index')->with('toast', ['type' => 'success', 'message' => 'Corrispettivo eliminato.']);
    }

    public function import(Request $request, GoldenRadioReceiptImporter $importer): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'format' => ['required', Rule::in(['golden_radio'])],
        ]);

        try {
            $result = $importer->import($validated['file']);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Importati {$result['created']} riepiloghi; duplicati saltati: {$result['skipped']}.",
        ]);
    }

    public function submit(Receipt $receipt, OpenApiReceiptService $service): RedirectResponse
    {
        try {
            $result = $service->submit($receipt);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['receipt' => $exception->getMessage()]);
        }

        if (! ($result['success'] ?? false)) {
            return back()->withErrors(['receipt' => $result['error'] ?? 'Trasmissione fallita.']);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Corrispettivo trasmesso a OpenAPI.']);
    }

    public function sync(Receipt $receipt, OpenApiReceiptService $service): RedirectResponse
    {
        try {
            $result = $service->sync($receipt);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['receipt' => $exception->getMessage()]);
        }

        if (! ($result['success'] ?? false)) {
            return back()->withErrors(['receipt' => $result['error'] ?? 'Sincronizzazione fallita.']);
        }

        return back()->with('toast', ['type' => 'success', 'message' => 'Stato OpenAPI aggiornato.']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::enum(ReceiptKind::class)],
            'status' => ['required', Rule::in([ReceiptStatus::Draft->value, ReceiptStatus::Recorded->value])],
            'transmission_channel' => ['required', Rule::enum(ReceiptTransmissionChannel::class)],
            'description' => ['required', 'string', 'max:255'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'transaction_count' => ['required', 'integer', 'min:1'],
            'external_reference' => ['nullable', 'string', 'max:255', Rule::unique('receipts')->ignore($request->route('receipt'))],
            'cash_payment_amount' => ['required', 'decimal:0,2', 'min:0'],
            'electronic_payment_amount' => ['required', 'decimal:0,2', 'min:0'],
            'uncollected_amount' => ['required', 'decimal:0,2', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_type' => ['required', Rule::in(['goods', 'service'])],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'decimal:0,2', 'gt:0'],
            'lines.*.unit_price_gross' => ['required', 'decimal:0,2'],
            'lines.*.vat_rate_code' => ['required', 'string', 'max:10'],
            'lines.*.vat_nature' => ['nullable', 'string', 'max:255'],
            'lines.*.country_group' => ['nullable', 'string', 'max:50'],
            'lines.*.net_amount' => ['required', 'decimal:0,2'],
            'lines.*.vat_amount' => ['required', 'decimal:0,2'],
            'lines.*.gross_amount' => ['required', 'decimal:0,2'],
        ]);

        if ($data['kind'] === ReceiptKind::MonthlySummary->value) {
            $data['transmission_channel'] = ReceiptTransmissionChannel::RegisterOnly->value;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'kinds' => array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], ReceiptKind::cases()),
            'statuses' => [
                ['value' => ReceiptStatus::Draft->value, 'label' => ReceiptStatus::Draft->label()],
                ['value' => ReceiptStatus::Recorded->value, 'label' => ReceiptStatus::Recorded->label()],
            ],
            'channels' => array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], ReceiptTransmissionChannel::cases()),
            'vatRates' => ['22', '10', '5', '4', '0', 'N1', 'N2', 'N2.1', 'N2.2', 'N3', 'N4', 'N5', 'N6'],
        ];
    }

    private function statusOptions(): array
    {
        return array_map(fn ($case) => ['value' => $case->value, 'label' => $case->label()], ReceiptStatus::cases());
    }

    /** @return array<string, mixed> */
    private function forForm(Receipt $receipt): array
    {
        return [
            'date' => $receipt->date->format('Y-m-d'),
            'kind' => $receipt->kind->value,
            'status' => in_array($receipt->status, [ReceiptStatus::Draft, ReceiptStatus::Recorded], true)
                ? $receipt->status->value
                : ReceiptStatus::Recorded->value,
            'transmission_channel' => $receipt->transmission_channel->value,
            'description' => $receipt->description,
            'period_start' => $receipt->period_start?->format('Y-m-d'),
            'period_end' => $receipt->period_end?->format('Y-m-d'),
            'transaction_count' => $receipt->transaction_count,
            'external_reference' => $receipt->external_reference,
            'cash_payment_amount' => $this->euros($receipt->cash_payment_amount),
            'electronic_payment_amount' => $this->euros($receipt->electronic_payment_amount),
            'uncollected_amount' => $this->euros($receipt->uncollected_amount),
            'lines' => $receipt->lines->map(fn ($line) => [
                'item_type' => $line->item_type,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price_gross' => $this->euros($line->unit_price_gross),
                'vat_rate_code' => $line->vat_rate_code,
                'vat_nature' => $line->vat_nature,
                'country_group' => $line->country_group,
                'net_amount' => $this->euros($line->net_amount),
                'vat_amount' => $this->euros($line->vat_amount),
                'gross_amount' => $this->euros($line->gross_amount),
            ])->all(),
        ];
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
