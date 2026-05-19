<?php

use App\Support\WorkScheduleNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        DB::table('companies')
            ->whereNotNull('work_schedule')
            ->orderBy('id')
            ->chunkById(100, function ($companies): void {
                foreach ($companies as $company) {
                    $raw = json_decode((string) $company->work_schedule, true);
                    if (! is_array($raw)) {
                        continue;
                    }

                    $normalized = WorkScheduleNormalizer::normalize($raw);
                    if ($normalized === null) {
                        continue;
                    }

                    DB::table('companies')
                        ->where('id', $company->id)
                        ->update([
                            'work_schedule' => json_encode($normalized, JSON_FORCE_OBJECT),
                        ]);
                }
            });
    }

    /**
     * @return void
     */
    public function down(): void
    {
    }
};
