<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('client_type', 16)
                ->default('web')
                ->after('name')
                ->index();
        });

        DB::table('personal_access_tokens')
            ->whereIn('name', ['access-token', 'refresh-token'])
            ->update(['client_type' => 'mobile']);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex(['client_type']);
            $table->dropColumn('client_type');
        });
    }
};
