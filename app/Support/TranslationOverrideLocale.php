<?php

namespace App\Support;

class TranslationOverrideLocale
{
    public const RU = 'ru';

    public const EN = 'en';

    public const TM = 'tm';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::RU,
            self::EN,
            self::TM,
        ];
    }
}
