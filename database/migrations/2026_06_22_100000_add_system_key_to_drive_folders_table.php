<?php

use App\Models\Company;
use App\Models\DriveFolder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYSTEM_KEY_PROJECTS = 'projects';

    public function up(): void
    {
        Schema::table('drive_folders', function (Blueprint $table) {
            $table->string('system_key', 64)->nullable()->after('project_id');
            $table->unique(['company_id', 'system_key']);
        });

        $definition = config('drive.system_folders.'.self::SYSTEM_KEY_PROJECTS, []);
        $definition = is_array($definition) ? $definition : [];
        $folderName = (string) ($definition['name'] ?? 'Проекты');

        Company::query()->orderBy('id')->each(function (Company $company) use ($folderName): void {
            $creatorId = (int) (DB::table('company_user')
                ->where('company_id', $company->id)
                ->orderBy('user_id')
                ->value('user_id') ?? 0);

            if ($creatorId <= 0) {
                $creatorId = (int) (DriveFolder::query()
                    ->where('company_id', $company->id)
                    ->orderBy('id')
                    ->value('creator_id') ?? 0);
            }

            if ($creatorId <= 0) {
                return;
            }

            $existingSystemFolder = DriveFolder::query()
                ->where('company_id', $company->id)
                ->where('system_key', self::SYSTEM_KEY_PROJECTS)
                ->first();

            if ($existingSystemFolder === null) {
                $nameConflict = DriveFolder::query()
                    ->where('company_id', $company->id)
                    ->whereNull('parent_id')
                    ->whereNull('system_key')
                    ->where('name', $folderName)
                    ->first();

                if ($nameConflict !== null) {
                    $suffix = 1;
                    $candidate = $folderName.' ('.$suffix.')';
                    while (DriveFolder::query()
                        ->where('company_id', $company->id)
                        ->whereNull('parent_id')
                        ->where('name', $candidate)
                        ->exists()) {
                        $suffix++;
                        $candidate = $folderName.' ('.$suffix.')';
                    }
                    $nameConflict->name = $candidate;
                    $nameConflict->save();
                }

                $existingSystemFolder = DriveFolder::query()->create([
                    'company_id' => $company->id,
                    'parent_id' => null,
                    'creator_id' => $creatorId,
                    'system_key' => self::SYSTEM_KEY_PROJECTS,
                    'name' => $folderName,
                    'icon' => (string) ($definition['icon'] ?? 'fas fa-folder'),
                    'icon_color' => (string) ($definition['icon_color'] ?? '#3B82F6'),
                ]);
            }

            DriveFolder::query()
                ->where('company_id', $company->id)
                ->whereNotNull('project_id')
                ->where(function ($query) use ($existingSystemFolder): void {
                    $query->whereNull('parent_id')
                        ->orWhere('parent_id', '!=', $existingSystemFolder->id);
                })
                ->update(['parent_id' => $existingSystemFolder->id]);
        });
    }

    public function down(): void
    {
        $projectsFolders = DriveFolder::query()
            ->where('system_key', self::SYSTEM_KEY_PROJECTS)
            ->get(['id', 'company_id']);

        foreach ($projectsFolders as $projectsFolder) {
            DriveFolder::query()
                ->where('company_id', $projectsFolder->company_id)
                ->where('parent_id', $projectsFolder->id)
                ->whereNotNull('project_id')
                ->update(['parent_id' => null]);
        }

        DriveFolder::query()
            ->where('system_key', self::SYSTEM_KEY_PROJECTS)
            ->delete();

        Schema::table('drive_folders', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'system_key']);
            $table->dropColumn('system_key');
        });
    }
};
