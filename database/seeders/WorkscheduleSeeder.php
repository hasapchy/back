<?php

namespace Database\Seeders;

use App\Support\DefaultWorkSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkscheduleSeeder extends Seeder
{
    /**
     * @return void
     */
    public function run(): void
    {
        DB::table('companies')->whereNull('work_schedule')->update([
            'work_schedule' => json_encode(DefaultWorkSchedule::get(), JSON_FORCE_OBJECT),
        ]);
    }
}
