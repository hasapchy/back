<?php

namespace App\Enums;

enum WhWriteoffReason: string
{
    case Defect = 'defect';
    case Shortage = 'shortage';
    case Consumable = 'consumable';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
