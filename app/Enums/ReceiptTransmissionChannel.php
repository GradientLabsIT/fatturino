<?php

namespace App\Enums;

enum ReceiptTransmissionChannel: string
{
    case RegisterOnly = 'register_only';
    case SmartReceipt = 'smart_receipt';
    case ElectronicReceipt = 'electronic_receipt';

    public function label(): string
    {
        return match ($this) {
            self::RegisterOnly => 'Solo registro',
            self::SmartReceipt => 'Smart Receipt (vendite online)',
            self::ElectronicReceipt => 'Corrispettivo elettronico (punto vendita)',
        };
    }
}
