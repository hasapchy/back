<?php

namespace App\Repositories;

use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\DrivePermission;
use App\Support\DriveFileRules;
use App\Support\DriveFolderAppearance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DriveRepository extends BaseRepository
{
    /**
     * @return array{parent: ?DriveFolder, folders: Collection<int, DriveFolder>, files: Collection<int, DriveFile>, breadcrumbs: array<int, array{id: int, name: string}>}
     */
    public function listContents(int $companyId, ?int $parentId): array
    {
        $parentFolder = null;
        if ($parentId !== null) {
            $parentFolder = DriveFolder::query()
                ->where('company_id', $companyId)
                ->findOrFail($parentId);
        }

        $folders = DriveFolder::query()
            ->where('company_id', $companyId)
            ->where('parent_id', $parentFolder?->id)
            ->with('creator:id,name,surname')
            ->orderBy('name')
            ->get();

        $files = DriveFile::query()
            ->where('company_id', $companyId)
            ->where('folder_id', $parentFolder?->id)
            ->with('creator:id,name,surname')
            ->orderBy('name')
            ->get();

        return [
            'parent' => $parentFolder,
            'folders' => $folders,
            'files' => $files,
            'breadcrumbs' => $parentFolder ? $this->breadcrumbs($parentFolder) : [],
        ];
    }

    /**
     * @param  array{name: string, parent_id?: int|null, icon?: string|null, icon_color?: string|null}  $validated
     */
    public function createFolder(int $companyId, int $creatorId, array $validated, ?DriveFolder $parentFolder): DriveFolder
    {
        return DriveFolder::query()->create([
            'company_id' => $companyId,
            'parent_id' => $parentFolder?->id,
            'creator_id' => $creatorId,
            'name' => trim($validated['name']),
            'icon' => DriveFolderAppearance::resolveIcon($validated['icon'] ?? null),
            'icon_color' => DriveFolderAppearance::resolveIconColor($validated['icon_color'] ?? null),
        ]);
    }

    public function folderNameExists(int $companyId, ?int $parentId, string $name, ?int $excludeId = null): bool
    {
        $query = DriveFolder::query()
            ->where('company_id', $companyId)
            ->where('parent_id', $parentId)
            ->where('name', trim($name));

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }

    /**
     * @param  array{name: string, icon?: string|null, icon_color?: string|null}  $validated
     */
    public function renameFolder(DriveFolder $folder, array $validated): DriveFolder
    {
        $folder->name = trim($validated['name']);
        if (array_key_exists('icon', $validated)) {
            $folder->icon = DriveFolderAppearance::resolveIcon($validated['icon']);
        }
        if (array_key_exists('icon_color', $validated)) {
            $folder->icon_color = DriveFolderAppearance::resolveIconColor($validated['icon_color']);
        }
        $folder->save();

        return $folder->fresh(['creator:id,name,surname']);
    }

    public function deleteFolder(DriveFolder $folder): void
    {
        $folderIds = $this->collectFolderIds($folder);
        $companyId = (int) $folder->company_id;
        $fileIds = DriveFile::query()
            ->where('company_id', $companyId)
            ->whereIn('folder_id', $folderIds)
            ->pluck('id')
            ->map(static fn ($value) => (int) $value)
            ->all();

        DB::transaction(function () use ($folder, $folderIds, $fileIds, $companyId): void {
            $this->deletePermissionsForResources($companyId, DrivePermission::RESOURCE_FOLDER, $folderIds);
            $this->deletePermissionsForResources($companyId, DrivePermission::RESOURCE_FILE, $fileIds);

            $files = DriveFile::query()->whereIn('id', $fileIds)->get();
            foreach ($files as $file) {
                Storage::disk($file->disk_name)->delete($file->path);
            }
            if ($fileIds !== []) {
                DriveFile::query()->whereIn('id', $fileIds)->delete();
            }

            $folder->delete();
        });
    }

    /**
     * @param  array<int, UploadedFile>  $rawFiles
     * @param  array<int, string|null>  $filePaths
     * @return array<int, DriveFile>
     */
    public function uploadFiles(
        int $companyId,
        int $creatorId,
        ?DriveFolder $folder,
        array $rawFiles,
        array $filePaths
    ): array {
        $createdFiles = [];

        DB::transaction(function () use ($rawFiles, $filePaths, $companyId, $folder, $creatorId, &$createdFiles): void {
            foreach ($rawFiles as $index => $file) {
                $originalName = $file->getClientOriginalName();
                $relativePath = $filePaths[$index] ?? null;
                $targetFolder = $folder;
                if (is_string($relativePath) && trim($relativePath) !== '') {
                    $pathParts = preg_split('/[\/\\\\]+/', trim($relativePath));
                    if (is_array($pathParts) && count($pathParts) > 1) {
                        array_pop($pathParts);
                        $targetFolder = $this->ensureFolderTree($companyId, $creatorId, $folder, $pathParts);
                    }
                }
                $extension = strtolower($file->getClientOriginalExtension());
                $storedName = Str::uuid().($extension !== '' ? '.'.$extension : '');
                $storageDir = 'drive/'.$companyId.'/'.($targetFolder ? $targetFolder->id : 'root');
                $path = $storageDir.'/'.$storedName;
                $file->storeAs($storageDir, $storedName, 'local');

                $createdFiles[] = DriveFile::query()->create([
                    'company_id' => $companyId,
                    'folder_id' => $targetFolder?->id,
                    'creator_id' => $creatorId,
                    'disk' => 'local',
                    'name' => $originalName,
                    'stored_name' => $storedName,
                    'path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'extension' => $extension,
                    'size' => $file->getSize() ?: 0,
                ]);
            }
        });

        return $createdFiles;
    }

    public function resolveRenameFileName(DriveFile $file, string $inputName): string
    {
        $baseName = trim($inputName);
        if ($baseName === '') {
            return '';
        }

        $extension = DriveFileRules::extensionFromDriveFile($file);
        if ($extension === '') {
            return $baseName;
        }

        $suffix = '.'.$extension;
        if (str_ends_with(strtolower($baseName), $suffix)) {
            $baseName = substr($baseName, 0, -strlen($suffix));
        }

        $baseName = rtrim(trim($baseName), '.');
        if ($baseName === '') {
            return '';
        }

        return $baseName.'.'.$extension;
    }

    public function fileNameExists(int $companyId, ?int $folderId, string $name, int $excludeId): bool
    {
        return DriveFile::query()
            ->where('company_id', $companyId)
            ->where('folder_id', $folderId)
            ->where('name', $name)
            ->where('id', '!=', $excludeId)
            ->exists();
    }

    public function renameFile(DriveFile $file, string $name): DriveFile
    {
        $file->name = $name;
        $file->save();

        return $file->fresh(['creator:id,name,surname', 'folder']);
    }

    public function deleteFile(DriveFile $file): void
    {
        DB::transaction(function () use ($file): void {
            $this->deletePermissionsForResources(
                (int) $file->company_id,
                DrivePermission::RESOURCE_FILE,
                [(int) $file->id]
            );
            Storage::disk($file->disk_name)->delete($file->path);
            $file->delete();
        });
    }

    /**
     * @param  array<int, int>  $fileIds
     * @return array<int, DriveFile>
     */
    public function moveFilesBatch(int $companyId, array $fileIds, ?DriveFolder $targetFolder): array
    {
        $moved = [];

        DB::transaction(function () use ($companyId, $fileIds, $targetFolder, &$moved): void {
            foreach ($fileIds as $fileId) {
                $file = DriveFile::query()
                    ->where('company_id', $companyId)
                    ->with('folder')
                    ->findOrFail((int) $fileId);
                $targetFolderId = $targetFolder?->id;
                if ((int) $file->folder_id === (int) $targetFolderId) {
                    continue;
                }
                $moved[] = $this->moveFileWithinTransaction($file, $targetFolder, $companyId);
            }
        });

        return $moved;
    }

    /**
     * @return Collection<int, DriveFile>
     */
    public function filesInFolderTree(int $companyId, DriveFolder $folder): Collection
    {
        $folderIds = $this->collectFolderIds($folder);

        return DriveFile::query()
            ->where('company_id', $companyId)
            ->whereIn('folder_id', $folderIds)
            ->with('folder')
            ->get();
    }

    /**
     * @return Collection<int, DrivePermission>
     */
    public function listPermissions(int $companyId, string $resourceType, int $resourceId): Collection
    {
        return DrivePermission::query()
            ->where('company_id', $companyId)
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<int, int>  $resourceIds
     */
    public function deletePermissionsForResources(int $companyId, string $resourceType, array $resourceIds): void
    {
        if ($resourceIds === []) {
            return;
        }

        DrivePermission::query()
            ->where('company_id', $companyId)
            ->where('resource_type', $resourceType)
            ->whereIn('resource_id', $resourceIds)
            ->delete();
    }

    private function moveFileWithinTransaction(DriveFile $file, ?DriveFolder $targetFolder, int $companyId): DriveFile
    {
        $extension = $file->extension ? '.'.$file->extension : '';
        $newStoredName = Str::uuid().$extension;
        $newPath = 'drive/'.$companyId.'/'.($targetFolder ? $targetFolder->id : 'root').'/'.$newStoredName;

        Storage::disk($file->disk_name)->move($file->path, $newPath);
        $file->folder_id = $targetFolder?->id;
        $file->stored_name = $newStoredName;
        $file->path = $newPath;
        $file->save();

        return $file;
    }

    /**
     * @param  array{resource_type: string, resource_id: int, subject_type: string, subject_id: int, ability: string, effect: string}  $validated
     */
    public function setPermission(int $companyId, int $createdBy, array $validated): DrivePermission
    {
        return DrivePermission::query()->updateOrCreate(
            [
                'company_id' => $companyId,
                'resource_type' => $validated['resource_type'],
                'resource_id' => (int) $validated['resource_id'],
                'subject_type' => $validated['subject_type'],
                'subject_id' => (int) $validated['subject_id'],
                'ability' => $validated['ability'],
            ],
            [
                'effect' => $validated['effect'],
                'created_by' => $createdBy,
            ]
        );
    }

    public function findFolder(int $companyId, int $id): DriveFolder
    {
        return DriveFolder::query()
            ->where('company_id', $companyId)
            ->findOrFail($id);
    }

    public function findOptionalFolder(int $companyId, ?int $id): ?DriveFolder
    {
        if ($id === null || $id <= 0) {
            return null;
        }

        return $this->findFolder($companyId, $id);
    }

    public function findFile(int $companyId, int $id): DriveFile
    {
        return DriveFile::query()
            ->where('company_id', $companyId)
            ->with('folder')
            ->findOrFail($id);
    }

    /**
     * @return array<int, int>
     */
    public function collectFolderIds(DriveFolder $folder): array
    {
        $ids = [(int) $folder->id];
        $cursor = [(int) $folder->id];
        while ($cursor !== []) {
            $children = DriveFolder::query()
                ->whereIn('parent_id', $cursor)
                ->pluck('id')
                ->map(static fn ($value) => (int) $value)
                ->all();
            if ($children === []) {
                break;
            }
            $ids = array_merge($ids, $children);
            $cursor = $children;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, int>
     */
    public function fileIdsInFolderTree(int $companyId, DriveFolder $folder): array
    {
        return $this->filesInFolderTree($companyId, $folder)
            ->pluck('id')
            ->map(static fn ($value) => (int) $value)
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function breadcrumbs(DriveFolder $folder): array
    {
        $items = [];
        $current = $folder;
        while ($current) {
            $items[] = ['id' => (int) $current->id, 'name' => $current->name];
            $current = $current->parent;
        }

        return array_reverse($items);
    }

    /**
     * @param  array<int, string>  $segments
     */
    private function ensureFolderTree(int $companyId, int $creatorId, ?DriveFolder $baseFolder, array $segments): ?DriveFolder
    {
        $parent = $baseFolder;
        foreach ($segments as $segment) {
            $name = trim((string) $segment);
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $parent = DriveFolder::query()->firstOrCreate(
                [
                    'company_id' => $companyId,
                    'parent_id' => $parent?->id,
                    'name' => $name,
                ],
                [
                    'creator_id' => $creatorId,
                    'icon' => DriveFolderAppearance::resolveIcon(null),
                    'icon_color' => DriveFolderAppearance::resolveIconColor(null),
                ]
            );
        }

        return $parent;
    }
}
