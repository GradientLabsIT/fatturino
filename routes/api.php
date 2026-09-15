<?php

use App\Http\Controllers\Api\AtecoSearchController;
use App\Http\Controllers\Api\PurchaseInvoiceCreateController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\SalesInvoiceCreateController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::middleware(['auth:sanctum'])->group(function () {
        Route::get('/ateco/search', AtecoSearchController::class);
        Route::post('/purchase-invoices', [PurchaseInvoiceCreateController::class, 'store']);
        Route::post('/sales-invoices', [SalesInvoiceCreateController::class, 'store']);
        Route::get('/receipts', [ReceiptController::class, 'index']);
        Route::post('/receipts', [ReceiptController::class, 'store']);
        Route::get('/receipts/{receipt}', [ReceiptController::class, 'show']);
        Route::post('/receipts/{receipt}/submit', [ReceiptController::class, 'submit']);
        Route::post('/receipts/{receipt}/sync', [ReceiptController::class, 'sync']);
    });
});
