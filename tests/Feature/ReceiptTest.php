<?php

use App\Enums\ReceiptStatus;
use App\Models\Receipt;
use App\Models\User;
use App\Services\GoldenRadioReceiptImporter;
use App\Services\OpenApiReceiptService;
use App\Services\ReceiptMutationService;
use App\Settings\CompanySettings;
use App\Settings\OpenApiSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

function receiptPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'date' => '2026-08-31',
        'kind' => 'monthly_summary',
        'status' => 'recorded',
        'transmission_channel' => 'register_only',
        'description' => 'Corrispettivi agosto',
        'transaction_count' => 3,
        'cash_payment_amount' => '0.00',
        'electronic_payment_amount' => '153.72',
        'uncollected_amount' => '0.00',
        'lines' => [[
            'item_type' => 'service',
            'description' => 'Vendite IT e UE',
            'quantity' => '1.00',
            'unit_price_gross' => '153.72',
            'vat_rate_code' => '22',
            'net_amount' => '126.00',
            'vat_amount' => '27.72',
            'gross_amount' => '153.72',
        ]],
    ], $overrides);
}

it('stores receipt money as exact cents and recalculates totals', function () {
    $receipt = app(ReceiptMutationService::class)->create(receiptPayload([
        'lines' => [[
            'item_type' => 'service',
            'description' => 'Arrotondamento HALF_UP',
            'quantity' => '1.00',
            'unit_price_gross' => '41.785',
            'vat_rate_code' => '22',
            'net_amount' => '34.25',
            'vat_amount' => '7.535',
            'gross_amount' => '41.785',
        ]],
    ]));

    expect($receipt->total_net)->toBe(3425)
        ->and($receipt->total_vat)->toBe(754)
        ->and($receipt->total_gross)->toBe(4179)
        ->and($receipt->lines->first()->unit_price_gross)->toBe(4179);
});

it('imports Golden Radio monthly summary idempotently', function () {
    $csv = implode("\n", [
        'Mese;N. vendite;Imponibile 22% (IT+UE);IVA 22%;Fuori campo 7-octies (extra-UE);UK lordo (da definire)',
        '2026-08;63;1104.22;243.01;527.82;269.91',
        'TOTALE;63;1104.22;243.01;527.82;269.91',
    ]);
    $file = UploadedFile::fake()->createWithContent('corrispettivi.csv', $csv);

    $first = app(GoldenRadioReceiptImporter::class)->import($file);
    $second = app(GoldenRadioReceiptImporter::class)->import($file);
    $receipt = Receipt::with('lines')->firstOrFail();

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['skipped'])->toBe(1)
        ->and($receipt->external_reference)->toBe('golden-radio:2026-08')
        ->and($receipt->transaction_count)->toBe(63)
        ->and($receipt->total_net)->toBe(190195)
        ->and($receipt->total_vat)->toBe(24301)
        ->and($receipt->total_gross)->toBe(214496)
        ->and($receipt->lines)->toHaveCount(3);
});

it('creates receipts through authenticated REST API', function () {
    $user = User::factory()->create();
    $token = $user->createToken('receipt-api-test')->plainTextToken;

    $this->postJson('/api/v1/receipts', receiptPayload())->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/receipts', receiptPayload(['external_reference' => 'api:test']))
        ->assertCreated()
        ->assertJsonPath('data.external_reference', 'api:test')
        ->assertJsonPath('data.total_gross', 15372);
});

it('submits an individual smart receipt using documented OpenAPI payload', function () {
    $company = app(CompanySettings::class);
    $company->company_vat_number = 'IT03002060428';
    $company->save();
    $settings = app(OpenApiSettings::class);
    $settings->api_token = 'receipt-test-token';
    $settings->sandbox = true;
    $settings->save();

    Http::fake([
        'https://test.invoice.openapi.com/IT-receipts' => Http::response([
            'success' => true,
            'data' => [
                'id' => 'receipt-provider-id',
                'status' => 'ready',
                'document_number' => 'OPENAPI2026/1',
            ],
        ]),
    ]);

    $receipt = app(ReceiptMutationService::class)->create(receiptPayload([
        'kind' => 'individual',
        'status' => 'draft',
        'transmission_channel' => 'smart_receipt',
        'description' => 'Licenza Golden Radio',
        'transaction_count' => 1,
    ]));

    $result = app(OpenApiReceiptService::class)->submit($receipt);

    expect($result['success'])->toBeTrue()
        ->and($receipt->fresh()->status)->toBe(ReceiptStatus::Ready)
        ->and($receipt->fresh()->provider_id)->toBe('receipt-provider-id');

    Http::assertSent(fn ($request) => $request->url() === 'https://test.invoice.openapi.com/IT-receipts'
        && $request->hasHeader('Authorization', 'Bearer receipt-test-token')
        && $request['fiscal_id'] === '03002060428'
        && $request['items'][0]['vat_rate_code'] === '22'
        && $request['electronic_payment_amount'] === 153.72);
});
