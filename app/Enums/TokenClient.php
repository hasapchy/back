<?php

namespace App\Enums;

enum TokenClient: string
{
    case Web = 'web';
    case Mobile = 'mobile';

    public static function default(): self
    {
        return self::Web;
    }
}
