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
use Spatie\Permission\Models\Role;
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
    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        return parent::actingAsApi($user, $company ?? $this->company);
    }

    /**
     * @return void
     */
    protected function grantDriveViewOwn(User $user): void
    {
        Permission::query()->firstOrCreate([
            'name' => 'drive_view_own',
            'guard_name' => 'api',
        ]);

        $role = Role::query()->create([
            'name' => 'drive_view_own_'.uniqid('', true),
            'guard_name' => 'api',
        ]);
        $role->givePermissionTo('drive_view_own');
        $user->companyRoles()->syncWithoutDetaching([
            $role->id => ['company_id' => $this->company->id],
        ]);
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
            'subject_id' => $subjectUser->id,
            'ability' => 'view',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('drive_permissions', [
            'company_id' => $this->company->id,
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_type' => 'user',
            'subject_id' => $subjectUser->id,
            'ability' => 'view',
            'effect' => 'allow',
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_permission_rejects_create_ability_for_file(): void
    {
        Storage::fake('local');
        $file = DriveFile::query()->create([
            'company_id' => $this->company->id,
            'folder_id' => null,
            'creator_id' => $this->adminUser->id,
            'disk' => 'local',
            'name' => 'shared.pdf',
            'stored_name' => 'shared.pdf',
            'path' => 'drive/'.$this->company->id.'/root/shared.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
        ]);
        $subjectUser = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $subjectUser->companies()->attach($this->company->id);

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/drive/permissions', [
            'resource_type' => 'file',
            'resource_id' => $file->id,
            'subject_id' => $subjectUser->id,
            'ability' => 'create',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('drive_permissions', [
            'resource_type' => 'file',
            'resource_id' => $file->id,
            'subject_id' => $subjectUser->id,
            'ability' => 'create',
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_set_update_permission_adds_view(): void
    {
        Storage::fake('local');
        $file = DriveFile::query()->create([
            'company_id' => $this->company->id,
            'folder_id' => null,
            'creator_id' => $this->adminUser->id,
            'disk' => 'local',
            'name' => 'acl.pdf',
            'stored_name' => 'acl.pdf',
            'path' => 'drive/'.$this->company->id.'/root/acl.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
        ]);
        $subjectUser = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $subjectUser->companies()->attach($this->company->id);

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/drive/permissions', [
            'resource_type' => 'file',
            'resource_id' => $file->id,
            'subject_id' => $subjectUser->id,
            'ability' => 'update',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('drive_permissions', [
            'resource_type' => 'file',
            'resource_id' => $file->id,
            'subject_id' => $subjectUser->id,
            'ability' => 'view',
        ]);
        $this->assertDatabaseHas('drive_permissions', [
            'resource_type' => 'file',
            'resource_id' => $file->id,
            'subject_id' => $subjectUser->id,
            'ability' => 'update',
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_sync_permissions_replaces_and_revokes(): void
    {
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'SyncFolder',
        ]);
        $subjectUser = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $subjectUser->companies()->attach($this->company->id);
        foreach (['view', 'update', 'delete'] as $ability) {
            DrivePermission::query()->create([
                'company_id' => $this->company->id,
                'resource_type' => 'folder',
                'resource_id' => $folder->id,
                'subject_type' => 'user',
                'subject_id' => $subjectUser->id,
                'ability' => $ability,
                'effect' => 'allow',
                'created_by' => $this->adminUser->id,
            ]);
        }

        $response = $this->actingAsApi($this->adminUser)->putJson('/api/drive/permissions', [
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_id' => $subjectUser->id,
            'abilities' => ['view'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.abilities', ['view']);
        $this->assertDatabaseHas('drive_permissions', [
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_id' => $subjectUser->id,
            'ability' => 'view',
        ]);
        $this->assertDatabaseMissing('drive_permissions', [
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_id' => $subjectUser->id,
            'ability' => 'update',
        ]);
    }

    /**
     * @return void
     */
    public function test_drive_view_own_denies_foreign_folder_without_acl(): void
    {
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'RestrictedFolder',
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);
        $this->grantDriveViewOwn($user);

        $response = $this->actingAsApi($user)->getJson('/api/drive?parent_id='.$folder->id);

        $response->assertStatus(403);
    }

    /**
     * @return void
     */
    public function test_drive_view_own_allows_foreign_folder_with_acl_allow(): void
    {
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'SharedFolder',
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);
        $this->grantDriveViewOwn($user);
        DrivePermission::query()->create([
            'company_id' => $this->company->id,
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'ability' => 'view',
            'effect' => 'allow',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($user)->getJson('/api/drive?parent_id='.$folder->id);

        $response->assertStatus(200);
    }

    /**
     * @return void
     */
    public function test_drive_preview_png_by_extension_and_mime(): void
    {
        Storage::fake('local');
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $path = 'drive/'.$this->company->id.'/root/'.'preview-test.png';
        Storage::disk('local')->put($path, $pngBytes);

        $file = DriveFile::query()->create([
            'company_id' => $this->company->id,
            'folder_id' => null,
            'creator_id' => $this->adminUser->id,
            'disk' => 'local',
            'name' => 'logo',
            'stored_name' => 'preview-test.png',
            'path' => $path,
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size' => strlen($pngBytes),
        ]);

        $response = $this->actingAsApi($this->adminUser)->get('/api/drive/files/'.$file->id.'/preview');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $baseResponse = $response->baseResponse;
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $baseResponse);
        $this->assertSame($pngBytes, file_get_contents($baseResponse->getFile()->getPathname()));
    }

    /**
     * @return void
     */
    public function test_drive_config_returns_allowlist(): void
    {
        $response = $this->actingAsApi($this->adminUser)->getJson('/api/drive/config');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                'allowed_file_extensions',
                'image_extensions',
                'max_file_bytes',
                'folder_icons',
                'folder_icon_color_default',
            ],
        ]);
        $this->assertContains('pdf', $response->json('data.allowed_file_extensions'));
    }

    /**
     * @return void
     */
    public function test_drive_delete_folder_removes_files_permissions_and_storage(): void
    {
        Storage::fake('local');
        $parent = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'Parent',
        ]);
        $child = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => $parent->id,
            'creator_id' => $this->adminUser->id,
            'name' => 'Child',
        ]);
        $path = 'drive/'.$this->company->id.'/'.$child->id.'/nested.pdf';
        Storage::disk('local')->put($path, 'pdf-content');
        $file = DriveFile::query()->create([
            'company_id' => $this->company->id,
            'folder_id' => $child->id,
            'creator_id' => $this->adminUser->id,
            'disk' => 'local',
            'name' => 'nested.pdf',
            'stored_name' => 'nested.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 11,
        ]);
        DrivePermission::query()->create([
            'company_id' => $this->company->id,
            'resource_type' => 'folder',
            'resource_id' => $child->id,
            'subject_type' => 'user',
            'subject_id' => $this->adminUser->id,
            'ability' => 'view',
            'effect' => 'allow',
            'created_by' => $this->adminUser->id,
        ]);
        DrivePermission::query()->create([
            'company_id' => $this->company->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
            'subject_type' => 'user',
            'subject_id' => $this->adminUser->id,
            'ability' => 'view',
            'effect' => 'allow',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->deleteJson('/api/drive/folders/'.$parent->id);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('drive_folders', ['id' => $parent->id]);
        $this->assertDatabaseMissing('drive_folders', ['id' => $child->id]);
        $this->assertDatabaseMissing('drive_files', ['id' => $file->id]);
        $this->assertDatabaseMissing('drive_permissions', [
            'resource_type' => 'folder',
            'resource_id' => $child->id,
        ]);
        $this->assertDatabaseMissing('drive_permissions', [
            'resource_type' => 'file',
            'resource_id' => $file->id,
        ]);
        Storage::disk('local')->assertMissing($path);
    }

    /**
     * @return void
     */
    public function test_drive_move_files_batch(): void
    {
        Storage::fake('local');
        $source = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'Source',
        ]);
        $target = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'Target',
        ]);
        $files = [];
        foreach (['a.pdf', 'b.pdf'] as $name) {
            $path = 'drive/'.$this->company->id.'/'.$source->id.'/'.$name;
            Storage::disk('local')->put($path, 'x');
            $files[] = DriveFile::query()->create([
                'company_id' => $this->company->id,
                'folder_id' => $source->id,
                'creator_id' => $this->adminUser->id,
                'disk' => 'local',
                'name' => $name,
                'stored_name' => $name,
                'path' => $path,
                'mime_type' => 'application/pdf',
                'extension' => 'pdf',
                'size' => 1,
            ]);
        }

        $response = $this->actingAsApi($this->adminUser)->postJson('/api/drive/files/move', [
            'file_ids' => array_map(static fn (DriveFile $f) => $f->id, $files),
            'target_folder_id' => $target->id,
        ]);

        $response->assertStatus(200);
        foreach ($files as $file) {
            $file->refresh();
            $this->assertSame($target->id, $file->folder_id);
            Storage::disk('local')->assertExists($file->path);
        }
    }

    /**
     * @return void
     */
    public function test_drive_upload_rejects_octet_stream_mime(): void
    {
        Storage::fake('local');
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'Uploads',
        ]);
        $tempPath = tempnam(sys_get_temp_dir(), 'drive');
        file_put_contents($tempPath, random_bytes(64));
        $file = new UploadedFile($tempPath, 'notes.txt', 'application/octet-stream', null, true);

        $response = $this->actingAsApi($this->adminUser)->post('/api/drive/files/upload', [
            'folder_id' => $folder->id,
            'files' => [$file],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DriveFile::query()->where('folder_id', $folder->id)->count());
    }

    /**
     * @return void
     */
    public function test_drive_list_permissions(): void
    {
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'Listed',
        ]);
        DrivePermission::query()->create([
            'company_id' => $this->company->id,
            'resource_type' => 'folder',
            'resource_id' => $folder->id,
            'subject_type' => 'user',
            'subject_id' => $this->adminUser->id,
            'ability' => 'view',
            'effect' => 'allow',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/drive/permissions?resource_type=folder&resource_id='.$folder->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.subject_id', $this->adminUser->id);
        $response->assertJsonPath('data.0.subject.name', $this->adminUser->name);
        $response->assertJsonPath('data.0.subject.surname', $this->adminUser->surname);
        $response->assertJsonPath('data.0.abilities', ['view']);
    }

    /**
     * @return void
     */
    public function test_drive_list_permissions_groups_abilities_by_subject(): void
    {
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'Grouped',
        ]);
        $subjectUser = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $subjectUser->companies()->attach($this->company->id);
        foreach (['view', 'update'] as $ability) {
            DrivePermission::query()->create([
                'company_id' => $this->company->id,
                'resource_type' => 'folder',
                'resource_id' => $folder->id,
                'subject_type' => 'user',
                'subject_id' => $subjectUser->id,
                'ability' => $ability,
                'effect' => 'allow',
                'created_by' => $this->adminUser->id,
            ]);
        }

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/drive/permissions?resource_type=folder&resource_id='.$folder->id);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.subject_id', $subjectUser->id);
        $response->assertJsonPath('data.0.abilities', ['view', 'update']);
    }

    /**
     * @return void
     */
    public function test_drive_index_marks_shared_file(): void
    {
        Storage::fake('local');
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'SharedFiles',
        ]);
        $file = DriveFile::query()->create([
            'company_id' => $this->company->id,
            'folder_id' => $folder->id,
            'creator_id' => $this->adminUser->id,
            'disk' => 'local',
            'name' => 'shared.pdf',
            'stored_name' => 'shared.pdf',
            'path' => 'drive/'.$this->company->id.'/'.$folder->id.'/shared.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
        ]);
        $subjectUser = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $subjectUser->companies()->attach($this->company->id);
        DrivePermission::query()->create([
            'company_id' => $this->company->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
            'subject_type' => 'user',
            'subject_id' => $subjectUser->id,
            'ability' => 'view',
            'effect' => 'allow',
            'created_by' => $this->adminUser->id,
        ]);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/drive?parent_id='.$folder->id);

        $response->assertStatus(200);
        $response->assertJsonPath('data.files.0.is_shared', true);
    }

    /**
     * @return void
     */
    public function test_drive_view_own_allows_file_without_folder_acl(): void
    {
        Storage::fake('local');
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'PrivateFolder',
        ]);
        $path = 'drive/'.$this->company->id.'/'.$folder->id.'/only-file.pdf';
        Storage::disk('local')->put($path, 'pdf-content');
        $file = DriveFile::query()->create([
            'company_id' => $this->company->id,
            'folder_id' => $folder->id,
            'creator_id' => $this->adminUser->id,
            'disk' => 'local',
            'name' => 'only-file.pdf',
            'stored_name' => 'only-file.pdf',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size' => 100,
        ]);
        $user = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $user->companies()->attach($this->company->id);
        $this->grantDriveViewOwn($user);
        DrivePermission::query()->create([
            'company_id' => $this->company->id,
            'resource_type' => 'file',
            'resource_id' => $file->id,
            'subject_type' => 'user',
            'subject_id' => $user->id,
            'ability' => 'view',
            'effect' => 'allow',
            'created_by' => $this->adminUser->id,
        ]);

        $folderListing = $this->actingAsApi($user)->getJson('/api/drive?parent_id='.$folder->id);
        $folderListing->assertStatus(200);
        $folderListing->assertJsonCount(1, 'data.files');
        $folderListing->assertJsonPath('data.files.0.id', $file->id);
        $folderListing->assertJsonCount(0, 'data.folders');

        $rootListing = $this->actingAsApi($user)->getJson('/api/drive');
        $rootListing->assertStatus(200);
        $rootListing->assertJsonPath('data.files.0.id', $file->id);

        $download = $this->actingAsApi($user)->get('/api/drive/files/'.$file->id.'/download');
        $download->assertStatus(200);
    }

    /**
     * @return void
     */
    public function test_drive_admin_ignores_folder_acl(): void
    {
        $folder = DriveFolder::query()->create([
            'company_id' => $this->company->id,
            'parent_id' => null,
            'creator_id' => $this->adminUser->id,
            'name' => 'AdminOnlyFolder',
        ]);
        $otherUser = User::factory()->create(['is_admin' => false, 'is_active' => true]);
        $otherUser->companies()->attach($this->company->id);

        $response = $this->actingAsApi($this->adminUser)->getJson('/api/drive?parent_id='.$folder->id);

        $response->assertStatus(200);
    }
}
