<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkscheduleSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        $defaultSchedule = [
            1 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            2 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            3 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            4 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            5 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'],
            6 => ['enabled' => false, 'start' => '10:00', 'end' => '14:00'],
            7 => ['enabled' => false, 'start' => '00:00', 'end' => '00:00'],
        ];

        DB::table('companies')->whereNull('work_schedule')->update([
            'work_schedule' => json_encode($defaultSchedule, JSON_FORCE_OBJECT),
        ]);
    }
}
