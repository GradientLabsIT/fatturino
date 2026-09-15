<?php

use App\Models\PurchaseInvoice;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('rejects purchase invoice api create without sanctum authentication', function () {
    $this->postJson('/api/v1/purchase-invoices', purchaseInvoicePayload())->assertUnauthorized();
});

it('creates an idempotent purchase invoice with supplier payment and PDF', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $token = $user->createToken('purchase-invoice-api-test-token')->plainTextToken;
    $payload = purchaseInvoicePayload();
    $payload['attachment'] = UploadedFile::fake()->createWithContent('INV-API-001.pdf', "%PDF-1.4\n%%EOF");

    $response = $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->post('/api/v1/purchase-invoices', $payload, ['Accept' => 'application/json']);

    $response->assertCreated()
        ->assertJsonPath('created', true)
        ->assertJsonPath('message', 'Costo creato.');

    $invoice = PurchaseInvoice::query()->findOrFail($response->json('purchase_invoice_id'));
    expect($invoice->contact->name)->toBe('Fornitore API S.r.l.')
        ->and($invoice->total_net)->toBe(9000)
        ->and($invoice->total_vat)->toBe(1980)
        ->and($invoice->total_gross)->toBe(10980)
        ->and($invoice->total_paid)->toBe(10980)
        ->and($invoice->payment_status->value)->toBe('paid')
        ->and($invoice->pdf_path)->not->toBeNull();
    Storage::disk('local')->assertExists($invoice->pdf_path);

    unset($payload['attachment']);
    $this
        ->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/purchase-invoices', $payload)
        ->assertOk()
        ->assertJsonPath('created', false)
        ->assertJsonPath('purchase_invoice_id', $invoice->id);

    expect(PurchaseInvoice::query()->count())->toBe(1);
});

function purchaseInvoicePayload(): array
{
    return [
        'supplier' => [
            'name' => 'Fornitore API S.r.l.',
            'vat_number' => 'IT12345678901',
            'country' => 'IT',
        ],
        'invoice_number' => 'INV-API-001',
        'date' => '2026-09-15',
        'due_date' => '2026-09-15',
        'external_source' => 'taxes-repo',
        'external_id' => 'transaction-001',
        'payment' => [
            'amount' => 109.80,
            'paid_at' => '2026-09-15',
            'payment_method' => 'MP08',
        ],
        'lines' => [[
            'description' => 'Servizio API',
            'quantity' => 1,
            'unit_price' => 100,
            'discount_percent' => 10,
            'vat_rate' => 'R22',
        ]],
    ];
}
