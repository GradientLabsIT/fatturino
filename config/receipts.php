<?php

return [
    'sandbox_url' => env('OPENAPI_INVOICE_SANDBOX_URL', 'https://test.invoice.openapi.com'),
    'production_url' => env('OPENAPI_INVOICE_PRODUCTION_URL', 'https://invoice.openapi.com'),
    'api_token' => env('OPENAPI_INVOICE_API_TOKEN', env('OPENAPI_SDI_API_TOKEN', '')),
    'store_id' => env('OPENAPI_RECEIPTS_STORE_ID'),
    'cash_register_id' => env('OPENAPI_RECEIPTS_CASH_REGISTER_ID'),
    'cashier_id' => env('OPENAPI_RECEIPTS_CASHIER_ID'),
];
