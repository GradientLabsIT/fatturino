<?php

namespace App\Enums;

enum ReceiptStatus: string
{
    case Draft = 'draft';
    case Recorded = 'recorded';
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Recorded => 'Registrato',
            self::Pending => 'In trasmissione',
            self::Ready => 'Trasmesso',
            self::Failed => 'Errore',
            self::Voided => 'Annullato',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Recorded, self::Failed], true);
    }
}
