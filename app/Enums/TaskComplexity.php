<?php

namespace App\Enums;

enum TaskComplexity: string
{
    case SIMPLE = 'simple';
    case NORMAL = 'normal';
    case COMPLEX = 'complex';

    public function label(): string
    {
        return match($this) {
            self::SIMPLE => 'простая',
            self::NORMAL => 'нормальная',
            self::COMPLEX => 'сложная',
        };
    }

    public function icons(): string
    {
        return match($this) {
            self::SIMPLE => '🧠',
            self::NORMAL => '🧠🧠',
            self::COMPLEX => '🧠🧠🧠',
        };
    }
}
