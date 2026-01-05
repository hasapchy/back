<?php

namespace App\Enums;

enum TaskPriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case HIGH = 'high';

    public function label(): string
    {
        return match($this) {
            self::LOW => 'низкий',
            self::NORMAL => 'нормальный',
            self::HIGH => 'высокий',
        };
    }

    public function icons(): string
    {
        return match($this) {
            self::LOW => '🔥',
            self::NORMAL => '🔥🔥',
            self::HIGH => '🔥🔥🔥',
        };
    }
}
