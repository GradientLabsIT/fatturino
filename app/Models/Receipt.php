<?php

namespace App\Models;

use App\Enums\ReceiptKind;
use App\Enums\ReceiptStatus;
use App\Enums\ReceiptTransmissionChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Receipt extends Model
{
    protected $guarded = [];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'period_start' => 'date:Y-m-d',
        'period_end' => 'date:Y-m-d',
        'kind' => ReceiptKind::class,
        'status' => ReceiptStatus::class,
        'transmission_channel' => ReceiptTransmissionChannel::class,
        'transaction_count' => 'integer',
        'total_net' => 'integer',
        'total_vat' => 'integer',
        'total_gross' => 'integer',
        'cash_payment_amount' => 'integer',
        'electronic_payment_amount' => 'integer',
        'uncollected_amount' => 'integer',
        'provider_payload' => 'array',
        'metadata' => 'array',
        'submitted_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $receipt) {
            $receipt->public_id ??= (string) Str::ulid();
            $receipt->fiscal_year ??= $receipt->date?->year ?? now()->year;
        });
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptLine::class);
    }

    public function recalculateTotals(): void
    {
        $this->forceFill([
            'total_net' => (int) $this->lines()->sum('net_amount'),
            'total_vat' => (int) $this->lines()->sum('vat_amount'),
            'total_gross' => (int) $this->lines()->sum('gross_amount'),
        ])->save();
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable() && $this->provider_id === null;
    }
}
