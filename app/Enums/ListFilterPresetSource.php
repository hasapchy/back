<?php

namespace App\Enums;

enum ListFilterPresetSource: string
{
    case Transactions = 'transactions';
    case Orders = 'orders';
    case Projects = 'projects';
    case Contracts = 'contracts';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
