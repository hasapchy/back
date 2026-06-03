<?php

namespace App\Http\Controllers\Api;

use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\DrivePermission;
use App\Models\User;
use App\Services\DriveAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DriveController extends BaseController
{
    public function __construct(
        private readonly DriveAccessService $driveAccessService
    ) {}

    /**
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $parentId = $request->input('parent_id');
        $parentFolder = null;
        if ($parentId !== null) {
            $parentFolder = DriveFolder::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $parentId);
            if (! $this->driveAccessService->can($user, $companyId, 'view', $parentFolder)) {
                return $this->errorResponse('Forbidden', 403);
            }
        }

        if (! $this->driveAccessService->passesBasePermission($user, 'view', $companyId)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $folders = DriveFolder::query()
            ->where('company_id', $companyId)
            ->where('parent_id', $parentFolder?->id)
            ->with('creator:id,name,surname')
            ->orderBy('name')
            ->get()
            ->filter(fn (DriveFolder $folder) => $this->driveAccessService->can($user, $companyId, 'view', $folder))
            ->values();

        $files = DriveFile::query()
            ->where('company_id', $companyId)
            ->where('folder_id', $parentFolder?->id)
            ->with('creator:id,name,surname')
            ->orderBy('name')
            ->get()
            ->filter(fn (DriveFile $file) => $this->driveAccessService->can($user, $companyId, 'view', $file->folder, $file))
            ->values();

        return $this->successResponse([
            'parent' => $parentFolder,
            'folders' => $folders,
            'files' => $files,
            'breadcrumbs' => $parentFolder ? $this->breadcrumbs($parentFolder) : [],
        ]);
    }

    /**
     * @return JsonResponse
     */
    public function createFolder(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'parent_id' => 'nullable|integer',
            'icon' => 'nullable|string|max:120',
        ]);

        $parentFolder = null;
        if (! empty($validated['parent_id'])) {
            $parentFolder = DriveFolder::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $validated['parent_id']);
        }

        if (! $this->driveAccessService->can($user, $companyId, 'upload', $parentFolder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $exists = DriveFolder::query()
            ->where('company_id', $companyId)
            ->where('parent_id', $parentFolder?->id)
            ->where('name', trim($validated['name']))
            ->exists();
        if ($exists) {
            return $this->errorResponse('Folder with this name already exists', 422);
        }

        $folder = DriveFolder::query()->create([
            'company_id' => $companyId,
            'parent_id' => $parentFolder?->id,
            'creator_id' => $user->id,
            'name' => trim($validated['name']),
            'icon' => isset($validated['icon']) ? trim((string) $validated['icon']) : null,
        ]);

        return $this->successResponse($folder, null, 201);
    }

    /**
     * @return JsonResponse
     */
    public function renameFolder(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:191',
            'icon' => 'nullable|string|max:120',
        ]);

        $folder = DriveFolder::query()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        if (! $this->driveAccessService->can($user, $companyId, 'rename', $folder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $duplicate = DriveFolder::query()
            ->where('company_id', $companyId)
            ->where('parent_id', $folder->parent_id)
            ->where('name', trim($validated['name']))
            ->where('id', '!=', $folder->id)
            ->exists();
        if ($duplicate) {
            return $this->errorResponse('Folder with this name already exists', 422);
        }

        $folder->name = trim($validated['name']);
        $folder->icon = isset($validated['icon']) ? trim((string) $validated['icon']) : $folder->icon;
        $folder->save();

        return $this->successResponse($folder);
    }

    /**
     * @return JsonResponse
     */
    public function deleteFolder(int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $folder = DriveFolder::query()
            ->where('company_id', $companyId)
            ->findOrFail($id);

        if (! $this->driveAccessService->can($user, $companyId, 'delete', $folder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $folderIds = $this->collectFolderIds($folder);
        $fileIds = DriveFile::query()
            ->where('company_id', $companyId)
            ->whereIn('folder_id', $folderIds)
            ->pluck('id')
            ->all();
        foreach ($fileIds as $fileId) {
            $file = DriveFile::query()->find($fileId);
            if ($file && ! $this->driveAccessService->can($user, $companyId, 'delete', $file->folder, $file)) {
                return $this->errorResponse('Forbidden', 403);
            }
        }

        DB::transaction(function () use ($folder, $folderIds): void {
            $paths = DriveFile::query()
                ->whereIn('folder_id', $folderIds)
                ->pluck('path')
                ->all();

            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }

            $folder->delete();
        });

        return $this->successResponse(null);
    }

    /**
     * @return JsonResponse
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validate([
            'folder_id' => 'nullable|integer',
            'files' => 'required',
            'files.*' => 'file|max:10240|mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,bmp,svg,zip,rar,7z,txt,md,csv,webp',
            'file_paths' => 'nullable|array',
            'file_paths.*' => 'nullable|string|max:500',
        ]);

        $folder = null;
        if (! empty($validated['folder_id'])) {
            $folder = DriveFolder::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $validated['folder_id']);
        }

        if (! $this->driveAccessService->can($user, $companyId, 'upload', $folder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $rawFiles = $request->file('files');
        if ($rawFiles instanceof UploadedFile) {
            $rawFiles = [$rawFiles];
        }
        if (! is_array($rawFiles) || count($rawFiles) === 0) {
            return $this->errorResponse('No files uploaded', 422);
        }

        $createdFiles = [];
        $filePaths = isset($validated['file_paths']) && is_array($validated['file_paths']) ? $validated['file_paths'] : [];
        DB::transaction(function () use ($rawFiles, $filePaths, $companyId, $folder, $user, &$createdFiles): void {
            foreach ($rawFiles as $index => $file) {
                $originalName = $file->getClientOriginalName();
                $relativePath = $filePaths[$index] ?? null;
                $targetFolder = $folder;
                if (is_string($relativePath) && trim($relativePath) !== '') {
                    $pathParts = preg_split('/[\/\\\\]+/', trim($relativePath));
                    if (is_array($pathParts) && count($pathParts) > 1) {
                        array_pop($pathParts);
                        $targetFolder = $this->ensureFolderTree($companyId, $user->id, $folder, $pathParts);
                    }
                }
                $extension = strtolower($file->getClientOriginalExtension());
                $storedName = Str::uuid().($extension !== '' ? '.'.$extension : '');
                $path = 'drive/'.$companyId.'/'.($targetFolder ? $targetFolder->id : 'root').'/'.$storedName;
                $file->storeAs('drive/'.$companyId.'/'.($targetFolder ? $targetFolder->id : 'root'), $storedName, 'local');

                $createdFiles[] = DriveFile::query()->create([
                    'company_id' => $companyId,
                    'folder_id' => $targetFolder?->id,
                    'creator_id' => $user->id,
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

        return $this->successResponse($createdFiles, null, 201);
    }

    /**
     * @return BinaryFileResponse|JsonResponse
     */
    public function download(int $id): BinaryFileResponse|JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $file = DriveFile::query()
            ->where('company_id', $companyId)
            ->with('folder')
            ->findOrFail($id);

        if (! $this->driveAccessService->can($user, $companyId, 'view', $file->folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if (! Storage::disk('local')->exists($file->path)) {
            return $this->errorResponse('File not found on disk', 404);
        }

        return response()->download(storage_path('app/'.$file->path), $file->name);
    }

    /**
     * @return JsonResponse
     */
    public function deleteFile(int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $file = DriveFile::query()
            ->where('company_id', $companyId)
            ->with('folder')
            ->findOrFail($id);

        if (! $this->driveAccessService->can($user, $companyId, 'delete', $file->folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        DB::transaction(function () use ($file): void {
            Storage::disk('local')->delete($file->path);
            $file->delete();
        });

        return $this->successResponse(null);
    }

    /**
     * @return JsonResponse
     */
    public function moveFile(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validate([
            'target_folder_id' => 'nullable|integer',
        ]);

        $file = DriveFile::query()
            ->where('company_id', $companyId)
            ->with('folder')
            ->findOrFail($id);

        if (! $this->driveAccessService->can($user, $companyId, 'rename', $file->folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $targetFolder = null;
        if (! empty($validated['target_folder_id'])) {
            $targetFolder = DriveFolder::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $validated['target_folder_id']);
            if (! $this->driveAccessService->can($user, $companyId, 'upload', $targetFolder)) {
                return $this->errorResponse('Forbidden', 403);
            }
        }

        $extension = $file->extension ? '.'.$file->extension : '';
        $newStoredName = Str::uuid().$extension;
        $newPath = 'drive/'.$companyId.'/'.($targetFolder ? $targetFolder->id : 'root').'/'.$newStoredName;

        DB::transaction(function () use ($file, $targetFolder, $newPath, $newStoredName): void {
            Storage::disk('local')->move($file->path, $newPath);
            $file->folder_id = $targetFolder?->id;
            $file->stored_name = $newStoredName;
            $file->path = $newPath;
            $file->save();
        });

        return $this->successResponse($file->fresh());
    }

    /**
     * @return JsonResponse
     */
    public function setPermission(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validate([
            'resource_type' => 'required|in:folder,file',
            'resource_id' => 'required|integer',
            'subject_type' => 'required|in:user,role',
            'subject_id' => 'required|integer',
            'ability' => 'required|in:view,upload,rename,delete,share',
            'effect' => 'required|in:allow,deny',
        ]);

        $folder = null;
        $file = null;
        if ($validated['resource_type'] === DrivePermission::RESOURCE_FOLDER) {
            $folder = DriveFolder::query()
                ->where('company_id', $companyId)
                ->findOrFail((int) $validated['resource_id']);
        } else {
            $file = DriveFile::query()
                ->where('company_id', $companyId)
                ->with('folder')
                ->findOrFail((int) $validated['resource_id']);
            $folder = $file->folder;
        }

        if (! $this->driveAccessService->can($user, $companyId, 'share', $folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if ($validated['subject_type'] === DrivePermission::SUBJECT_USER) {
            User::query()->findOrFail((int) $validated['subject_id']);
        }

        $permission = DrivePermission::query()->updateOrCreate(
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
                'created_by' => $user->id,
            ]
        );

        return $this->successResponse($permission);
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
     * @return array<int, int>
     */
    private function collectFolderIds(DriveFolder $folder): array
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
                    'icon' => 'fas fa-folder',
                ]
            );
        }

        return $parent;
    }
}
