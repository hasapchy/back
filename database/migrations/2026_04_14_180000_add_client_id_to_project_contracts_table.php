<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('project_id')->constrained('clients')->nullOnDelete();
        });

        DB::table('project_contracts')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                $clientId = DB::table('projects')->where('id', $row->project_id)->value('client_id');
                DB::table('project_contracts')->where('id', $row->id)->update(['client_id' => $clientId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_contracts', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
        });
    }
};
