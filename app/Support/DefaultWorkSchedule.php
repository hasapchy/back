<?php

namespace App\Support;

class DefaultWorkSchedule
{
    /**
     * @return array<int, array{enabled: bool, start: string, end: string}>
     */
    public static function get(): array
    {
        return [
            1 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            2 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            3 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            4 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            5 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            6 => ['enabled' => false, 'start' => '10:00', 'end' => '14:00'],
            7 => ['enabled' => false, 'start' => '00:00', 'end' => '00:00'],
        ];
    }
}
