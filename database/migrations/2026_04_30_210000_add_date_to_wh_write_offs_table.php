<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wh_write_offs', function (Blueprint $table) {
            $table->date('date')->nullable()->after('note');
        });

        DB::table('wh_write_offs')
            ->select(['id', 'created_at'])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('wh_write_offs')
                        ->where('id', $row->id)
                        ->update([
                            'date' => Carbon::parse($row->created_at)->toDateString(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('wh_write_offs', function (Blueprint $table) {
            $table->dropColumn('date');
        });
    }
};
