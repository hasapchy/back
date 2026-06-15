<?php

namespace App\Enums;

enum WhReceiptStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Completed = 'completed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
