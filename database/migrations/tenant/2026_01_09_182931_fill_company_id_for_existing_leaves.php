<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Заполняет company_id для существующих leaves на основе company_user из центральной БД.
     * company_user хранится в central, leaves — в tenant, поэтому JOIN в одном запросе невозможен.
     */
    public function up(): void
    {
        $centralConnection = config('tenancy.database.central_connection', 'mysql');
        $userCompanyMap = DB::connection($centralConnection)
            ->table('company_user')
            ->select('user_id', 'company_id')
            ->orderBy('id')
            ->get()
            ->unique('user_id')
            ->keyBy('user_id');

        $leaves = DB::table('leaves')->whereNull('company_id')->get();

        foreach ($leaves as $leave) {
            $companyId = $userCompanyMap->get($leave->user_id)?->company_id;
            if ($companyId) {
                DB::table('leaves')->where('id', $leave->id)->update(['company_id' => $companyId]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Не можем безопасно откатить, так как не знаем, какие записи были обновлены
        // Оставляем company_id как есть
    }
};
