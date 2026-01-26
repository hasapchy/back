<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkscheduleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $defaultSchedule = [
            1 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Monday
            2 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Tuesday
            3 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Wednesday
            4 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Thursday
            5 => ['enabled' => true, 'start' => '09:00', 'end' => '18:00'], // Friday
            6 => ['enabled' => false, 'start' => '10:00', 'end' => '14:00'], // Saturday
            7 => ['enabled' => false, 'start' => '00:00', 'end' => '00:00']  // Sunday
        ];

        DB::table('companies')->whereNull('work_schedule')->update([
            'work_schedule' => json_encode($defaultSchedule, JSON_FORCE_OBJECT)
        ]);
    }
}
