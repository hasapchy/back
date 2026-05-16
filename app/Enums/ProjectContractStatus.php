<?php

namespace App\Enums;

enum ProjectContractStatus: string
{
    case Draft = 'draft';
    case Active = 'active';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
