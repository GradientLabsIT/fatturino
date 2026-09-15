<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Enums\ReceiptTransmissionChannel;
use App\Http\Controllers\Controller;
use App\Models\Receipt;
use App\Services\OpenApiReceiptService;
use App\Services\ReceiptMutationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $receipts = Receipt::query()->with('lines')
            ->when($request->integer('year'), fn ($q, $year) => $q->where('fiscal_year', $year))
            ->latest('date')->paginate(min($request->integer('per_page', 50), 100));

        return response()->json($receipts);
    }

    public function show(Receipt $receipt): JsonResponse
    {
        return response()->json(['data' => $receipt->load('lines')]);
    }

    public function store(Request $request, ReceiptMutationService $mutation): JsonResponse
    {
        $data = $request->validate($this->rules());
        $receipt = $mutation->create($data + ['source' => 'api']);

        return response()->json(['data' => $receipt], 201);
    }

    public function submit(Receipt $receipt, OpenApiReceiptService $service): JsonResponse
    {
        $result = $service->submit($receipt);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    public function sync(Receipt $receipt, OpenApiReceiptService $service): JsonResponse
    {
        $result = $service->sync($receipt);

        return response()->json($result, ($result['success'] ?? false) ? 200 : 422);
    }

    private function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'kind' => ['required', Rule::enum(ReceiptKind::class)],
            'status' => ['sometimes', Rule::in([ReceiptStatus::Draft->value, ReceiptStatus::Recorded->value])],
            'transmission_channel' => ['required', Rule::enum(ReceiptTransmissionChannel::class)],
            'description' => ['required', 'string', 'max:255'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
            'transaction_count' => ['sometimes', 'integer', 'min:1'],
            'external_reference' => ['nullable', 'string', 'max:255', 'unique:receipts,external_reference'],
            'cash_payment_amount' => ['sometimes', 'numeric', 'min:0'],
            'electronic_payment_amount' => ['sometimes', 'numeric', 'min:0'],
            'uncollected_amount' => ['sometimes', 'numeric', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_type' => ['sometimes', Rule::in(['goods', 'service'])],
            'lines.*.description' => ['required', 'string', 'max:1000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price_gross' => ['required', 'numeric'],
            'lines.*.vat_rate_code' => ['required', 'string', 'max:10'],
            'lines.*.vat_nature' => ['nullable', 'string', 'max:255'],
            'lines.*.country_group' => ['nullable', 'string', 'max:50'],
            'lines.*.net_amount' => ['required', 'numeric'],
            'lines.*.vat_amount' => ['required', 'numeric'],
            'lines.*.gross_amount' => ['required', 'numeric'],
        ];
    }
}
