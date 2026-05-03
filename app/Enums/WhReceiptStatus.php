<?php

namespace App\Enums;

enum WhReceiptStatus: string
{
    case InTransit = 'in_transit';
    case CustomsClearance = 'customs_clearance';
    case Purchasing = 'purchasing';
    case FullyReceived = 'fully_received';
    case Completed = 'completed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
