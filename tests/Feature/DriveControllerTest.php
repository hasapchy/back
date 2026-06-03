<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\DrivePermission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DriveControllerTest extends TestCase
{
    protected User $adminUser;

    protected Company $company;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        [$this->company, $this->adminUser] = $this->createCompanyWithAdminUser();
    }

    /**
     * @return self
     */
    protected function actingAsApi(User $user): self
    {
        return $this->withApiTokenForCompany($user, (int) $this->company->id);
    }

    /**
     * @return void
     */
    public function test_drive_index_returns_success_for_admin(): void
    {
        $response = $this->actingAsApi($this->adminUser)->getJson('/api/drive');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'folders',
                'files',
                'breadcrumbs',
            ],
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_create_folder_success(): void
    {
        $response = $this->actingAsApi($this->adminUser)->postJson('/api/drive/folders', [
            'name' => 'RootFolder',
            'parent_id' => null,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('drive_folders', [
            'company_id' => $this->company->id,
            'name' => 'RootFolder',
            'parent_id' => null,
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_upload_file_success(): void
    {
        Storage::fake('local');
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'Uploads',
        ]);

        $response = $this->actingAsApi($this->adminUser)->post('/api/drive/files/upload', [
            'folder_id' => $folder->id,
            'files' => [UploadedFile::fake()->create('sample.pdf', 100, 'application/pdf')],
        ]);

        $response->assertStatus(201);
        $fileId = (int) $response->json('data.0.id');
        $file = DriveFile::query()->findOrFail($fileId);
        $this->assertDatabaseHas('drive_files', [
            'id' => $file->id,
            'company_id' => $this->company->id,
            'folder_id' => $folder->id,
            'name' => 'sample.pdf',
        ]);
        Storage::disk('local')->assertExists($file->path);
    }

    /**
     * @return void
     */
    public function test_drive_rename_file_success(): void
    {
        Storage::fake('local');
        $file = DriveFile::query()->create([
            'company_id' => $this->company->id,
            'folder_id' => null,
            'creator_id' => $this->adminUser->id,
            'disk' => 'local',
            'name' => 'old-name.pdf',
            'stored_name' => 'stored.pdf',
            'path' => 'drive/'.$this->company->id.'/root/stored.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
        ]);

        $response = $this->actingAsApi($this->adminUser)->putJson('/api/drive/files/'.$file->id, [
            'name' => 'new-name.pdf',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('drive_files', [
            'id' => $file->id,
            'name' => 'new-name.pdf',
            'extension' => 'pdf',
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_rename_file_keeps_extension_when_basename_only(): void
    {
        Storage::fake('local');
        $file = DriveFile::query()->create([
            'company_id' => $this->company->id,
            'folder_id' => null,
            'creator_id' => $this->adminUser->id,
            'disk' => 'local',
            'name' => 'old-name.pdf',
            'stored_name' => 'stored.pdf',
            'path' => 'drive/'.$this->company->id.'/root/stored.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
        ]);

        $response = $this->actingAsApi($this->adminUser)->putJson('/api/drive/files/'.$file->id, [
            'name' => 'new-name',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('drive_files', [
            'id' => $file->id,
            'name' => 'new-name.pdf',
            'extension' => 'pdf',
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_permission_endpoint_creates_acl_rule(): void
    {
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'SecureFolder',
        ]);
        $subjectUser = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $subjectUser->companies()->attach($this->company->id);

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/drive/permissions', [
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_type' => 'user',
            'subject_id' => $subjectUser->id,
            'ability' => 'view',
            'effect' => 'deny',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('drive_permissions', [
            'company_id' => $this->company->id,
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_type' => 'user',
            'subject_id' => $subjectUser->id,
            'ability' => 'view',
            'effect' => 'deny',
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_access_denied_by_acl_for_non_admin_user(): void
    {
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'RestrictedFolder',
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);
        $viewPermission = Permission::query()->firstOrCreate([
            'name' => 'drive_view',
            'guard_name' => 'api',
        ]);
        $user->givePermissionTo($viewPermission);
        DrivePermission::query()->create([
            'company_id' => $this->company->id,
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'ability' => 'view',
            'effect' => 'deny',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($user)->getJson('/api/drive?parent_id='.$folder->id);

        $response->assertStatus(403);
    }
}
