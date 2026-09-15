<?php

namespace App\Enums;

enum ReceiptKind: string
{
    case Individual = 'individual';
    case MonthlySummary = 'monthly_summary';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Documento commerciale',
            self::MonthlySummary => 'Riepilogo mensile',
        };
    }
}
