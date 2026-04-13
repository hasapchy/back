<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_simple_user')->default(false)->after('is_admin');
        });

        $roleId = DB::table('roles')
            ->where('name', 'basement_worker')
            ->where('guard_name', 'api')
            ->value('id');

        if ($roleId) {
            $fromCompany = DB::table('company_user_role')
                ->where('role_id', $roleId)
                ->pluck('creator_id');

            $fromDirect = DB::table('model_has_roles')
                ->where('role_id', $roleId)
                ->where('model_type', User::class)
                ->pluck('model_id');

            $ids = $fromCompany->merge($fromDirect)->unique()->filter()->values();
            if ($ids->isNotEmpty()) {
                DB::table('users')->whereIn('id', $ids)->update(['is_simple_user' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_simple_user');
        });
    }
};
