<?php

use App\Enums\InvoiceStatus;
use App\Enums\SdiStatus;
use App\Models\FiscalDocument;
use App\Services\FattureInCloudHistoryImporter;
use Illuminate\Support\Facades\Http;

function ficPage(array $documents): array
{
    return ['data' => $documents];
}

test('imports FIC history without calling any send endpoint and remains idempotent', function () {
    $invoice = [
        'id' => 101,
        'type' => 'invoice',
        'year' => 2026,
        'number' => 3,
        'numeration' => '/A',
        'date' => '2026-05-10',
        'amount_net' => 100,
        'amount_vat' => 22,
        'amount_gross' => 122,
        'amount_withholding_tax' => 0,
        'stamp_duty' => 0,
        'use_split_payment' => false,
        'e_invoice' => true,
        'ei_status' => 'sent',
        'entity' => ['id' => 50, 'name' => 'Cliente', 'vat_number' => 'IT123', 'country' => 'Italia'],
        'items_list' => [['name' => 'Servizio', 'qty' => 1, 'net_price' => 100, 'discount' => 0, 'vat' => ['value' => 22]]],
        'payments_list' => [['id' => 1, 'amount' => 122, 'status' => 'paid', 'paid_date' => '2026-05-10', 'due_date' => '2026-05-10']],
        'created_at' => '2026-05-10 10:00:00',
        'updated_at' => '2026-05-10 11:00:00',
    ];
    $expense = [
        'id' => 201,
        'type' => 'expense',
        'invoice_number' => 'SUP-1',
        'date' => '2026-05-11',
        'amount_net' => 50,
        'amount_vat' => 0,
        'amount_gross' => 50,
        'amount_withholding_tax' => 0,
        'e_invoice' => false,
        'entity' => ['id' => 60, 'name' => 'Fornitore', 'vat_number' => 'DE123', 'country' => 'Germania'],
        'items_list' => null,
        'payments_list' => [],
        'description' => 'Costo hosting',
        'category' => 'Hosting',
    ];

    Http::fake(function ($request) use ($invoice, $expense) {
        expect($request->method())->toBe('GET');
        expect($request->url())->not->toContain('/send');

        if (str_contains($request->url(), '/received_documents')) {
            return Http::response(ficPage([$expense]));
        }
        if (($request['type'] ?? null) === 'invoice') {
            return Http::response(ficPage([$invoice]));
        }

        return Http::response(ficPage([]));
    });

    $importer = app(FattureInCloudHistoryImporter::class);
    $first = $importer->importYear('secret', 1, 2026, false);
    $second = $importer->importYear('secret', 1, 2026, false);

    expect($first['created'])->toBe(2)
        ->and($second['created'])->toBe(0)
        ->and($second['skipped'])->toBe(2)
        ->and(FiscalDocument::query()->count())->toBe(2);

    $sales = FiscalDocument::query()->where('external_id', '101')->firstOrFail();
    expect($sales->number)->toBe('3/A')
        ->and($sales->statusValue())->toBe(InvoiceStatus::Sent->value)
        ->and($sales->sdi_status)->toBe(SdiStatus::Sent)
        ->and($sales->total_gross)->toBe(12200)
        ->and($sales->total_paid)->toBe(12200);

    Http::assertSent(fn ($request) => $request->method() === 'GET' && ! str_contains($request->url(), '/send'));
});
