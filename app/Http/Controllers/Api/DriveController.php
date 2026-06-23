<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\ListDrivePermissionsRequest;
use App\Http\Requests\MoveDriveFilesRequest;
use App\Http\Requests\RenameDriveFileRequest;
use App\Http\Requests\SetDrivePermissionRequest;
use App\Http\Requests\SyncDrivePermissionRequest;
use App\Http\Requests\StoreDriveFolderRequest;
use App\Http\Requests\UpdateDriveFolderRequest;
use App\Http\Requests\UploadDriveFilesRequest;
use App\Http\Resources\DriveFileResource;
use App\Http\Resources\DriveFolderResource;
use App\Http\Resources\DriveListingResource;
use App\Http\Resources\DrivePermissionResource;
use App\Models\DriveFile;
use App\Models\DriveFolder;
use App\Models\DrivePermission;
use App\Models\User;
use App\Repositories\DriveRepository;
use App\Services\DriveAccessService;
use App\Support\DriveFileRules;
use App\Support\DriveSystemFolders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DriveController extends BaseController
{
    public function __construct(
        private readonly DriveAccessService $driveAccessService,
        private readonly DriveRepository $driveRepository,
    ) {}

    /**
     * @return JsonResponse
     */
    public function config(): JsonResponse
    {
        $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        return $this->successResponse(DriveFileRules::publicConfig());
    }

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
        $parentIdInt = $parentId !== null && $parentId !== '' ? (int) $parentId : null;
        $viewAbilities = $this->driveAccessService->aclAbilitiesFor('view');

        if ($parentIdInt !== null) {
            $parentFolder = $this->driveRepository->findFolder($companyId, $parentIdInt);
            if (! $this->canBrowseFolder($user, $companyId, $parentFolder, $viewAbilities)) {
                return $this->errorResponse('Forbidden', 403);
            }
        }

        if (! $this->driveAccessService->passesBasePermission($user, 'view', $companyId)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if ($parentIdInt === null) {
            $this->driveRepository->ensureProjectsSystemFolder($companyId, (int) $user->id);
        }

        $listing = $this->driveRepository->listContents($companyId, $parentIdInt);

        $listing['folders'] = $listing['folders']
            ->filter(fn (DriveFolder $folder) => $this->folderVisibleToUser($user, $companyId, $folder, $viewAbilities))
            ->values();

        $listing['files'] = $listing['files']
            ->filter(fn (DriveFile $file) => $this->driveAccessService->can($user, $companyId, 'view', $file->folder, $file))
            ->values();

        if ($parentIdInt === null) {
            $listing['files'] = $this->appendRootDirectAclFiles($user, $companyId, $listing['files'], $viewAbilities);
        }

        return $this->successResponse(
            DriveListingResource::make($listing)->resolve()
        );
    }

    /**
     * @param  array<int, string>  $viewAbilities
     */
    private function canBrowseFolder(User $user, int $companyId, DriveFolder $folder, array $viewAbilities): bool
    {
        if ($this->folderVisibleToUser($user, $companyId, $folder, $viewAbilities)) {
            return true;
        }

        return $this->driveRepository->userHasDirectFileViewInFolder(
            $companyId,
            (int) $user->id,
            (int) $folder->id,
            $viewAbilities
        );
    }

    /**
     * @param  array<int, string>  $viewAbilities
     */
    private function folderVisibleToUser(User $user, int $companyId, DriveFolder $folder, array $viewAbilities): bool
    {
        if ($this->driveAccessService->can($user, $companyId, 'view', $folder)) {
            return true;
        }

        if (! DriveSystemFolders::isProjectsContainer($folder)) {
            return false;
        }

        return $this->driveRepository->projectsContainerHasBrowsableChild(
            $companyId,
            (int) $folder->id,
            $user,
            $viewAbilities
        );
    }

    /**
     * @param  Collection<int, DriveFile>  $listedFiles
     * @param  array<int, string>  $viewAbilities
     * @return Collection<int, DriveFile>
     */
    private function appendRootDirectAclFiles(User $user, int $companyId, Collection $listedFiles, array $viewAbilities): Collection
    {
        if ($user->is_admin) {
            return $listedFiles;
        }

        $listedIds = $listedFiles->pluck('id')->flip();
        $directAclFiles = $this->driveRepository->listFilesWithDirectUserAcl(
            $companyId,
            (int) $user->id,
            $viewAbilities
        );

        $extra = $directAclFiles->filter(function (DriveFile $file) use ($user, $companyId, $listedIds) {
            if ($listedIds->has($file->id)) {
                return false;
            }

            if (! $this->driveAccessService->can($user, $companyId, 'view', $file->folder, $file)) {
                return false;
            }

            if ($file->folder_id === null) {
                return false;
            }

            if ($file->folder && $this->driveAccessService->can($user, $companyId, 'view', $file->folder)) {
                return false;
            }

            return true;
        });

        return $listedFiles->merge($extra)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @return JsonResponse
     */
    public function createFolder(StoreDriveFolderRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validated();
        $parentFolder = $this->driveRepository->findOptionalFolder(
            $companyId,
            ! empty($validated['parent_id']) ? (int) $validated['parent_id'] : null
        );

        if (! $this->driveAccessService->can($user, $companyId, 'create', $parentFolder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if ($parentFolder !== null && DriveSystemFolders::isProjectsContainer($parentFolder)) {
            return $this->errorResponse(__('api.drive.system_folder_create_child_forbidden'), 422);
        }

        if ($this->driveRepository->folderNameExists($companyId, $parentFolder?->id, $validated['name'])) {
            return $this->errorResponse('Folder with this name already exists', 422);
        }

        $folder = $this->driveRepository->createFolder($companyId, $user->id, $validated, $parentFolder);
        $folder->load('creator:id,name,surname');

        return $this->successResponse(
            DriveFolderResource::make($folder)->resolve(),
            null,
            201
        );
    }

    /**
     * @return JsonResponse
     */
    public function renameFolder(UpdateDriveFolderRequest $request, int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validated();
        $folder = $this->driveRepository->findFolder($companyId, $id);

        if (! $this->driveAccessService->can($user, $companyId, 'update', $folder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if (DriveSystemFolders::isSystemFolder($folder)) {
            return $this->errorResponse(__('api.drive.system_folder_update_forbidden'), 422);
        }

        if ($folder->project_id && trim($validated['name']) !== $folder->name) {
            return $this->errorResponse(__('api.drive.project_folder_rename_forbidden'), 422);
        }

        if (! $folder->project_id && $this->driveRepository->folderNameExists($companyId, $folder->parent_id, $validated['name'], $folder->id)) {
            return $this->errorResponse('Folder with this name already exists', 422);
        }

        $folder = $this->driveRepository->renameFolder($folder, $validated);

        return $this->successResponse(DriveFolderResource::make($folder)->resolve());
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

        $folder = $this->driveRepository->findFolder($companyId, $id);

        if (! $this->driveAccessService->can($user, $companyId, 'delete', $folder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if (DriveSystemFolders::isSystemFolder($folder)) {
            return $this->errorResponse(__('api.drive.system_folder_delete_forbidden'), 422);
        }

        if ($folder->project_id) {
            return $this->errorResponse(__('api.drive.project_folder_delete_forbidden'), 422);
        }

        foreach ($this->driveRepository->filesInFolderTree($companyId, $folder) as $file) {
            if (! $this->driveAccessService->can($user, $companyId, 'delete', $file->folder, $file)) {
                return $this->errorResponse('Forbidden', 403);
            }
        }

        $this->driveRepository->deleteFolder($folder);

        return $this->successResponse(null);
    }

    /**
     * @return JsonResponse
     */
    public function upload(UploadDriveFilesRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validated();
        $folder = $this->driveRepository->findOptionalFolder(
            $companyId,
            ! empty($validated['folder_id']) ? (int) $validated['folder_id'] : null
        );

        if (! $this->driveAccessService->can($user, $companyId, 'create', $folder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if ($folder !== null && DriveSystemFolders::isProjectsContainer($folder)) {
            return $this->errorResponse(__('api.drive.system_folder_upload_forbidden'), 422);
        }

        $rawFiles = $request->file('files');
        if ($rawFiles instanceof UploadedFile) {
            $rawFiles = [$rawFiles];
        }
        if (! is_array($rawFiles) || count($rawFiles) === 0) {
            return $this->errorResponse('No files uploaded', 422);
        }

        $maxBytes = DriveFileRules::maxFileBytes();
        foreach ($rawFiles as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return $this->errorResponse('Invalid file upload', 422);
            }
            if ($file->getSize() > $maxBytes) {
                return $this->errorResponse('File exceeds 10MB', 422);
            }
            if (! DriveFileRules::isAllowedUploadedFile($file)) {
                return $this->errorResponse(DriveFileRules::unsupportedUploadMessage($file), 422);
            }
        }

        $filePaths = isset($validated['file_paths']) && is_array($validated['file_paths']) ? $validated['file_paths'] : [];
        $createdFiles = $this->driveRepository->uploadFiles($companyId, $user->id, $folder, $rawFiles, $filePaths);

        return $this->successResponse(
            DriveFileResource::collection($createdFiles)->resolve(),
            null,
            201
        );
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

        $file = $this->driveRepository->findFile($companyId, $id);

        if (! $this->driveAccessService->can($user, $companyId, 'view', $file->folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if (! Storage::disk($file->disk_name)->exists($file->path)) {
            return $this->errorResponse('File not found on disk', 404);
        }

        return $this->fileBinaryResponse($file, false);
    }

    /**
     * @return BinaryFileResponse|JsonResponse
     */
    public function view(int $id): BinaryFileResponse|JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $file = $this->driveRepository->findFile($companyId, $id);

        if (! $this->driveAccessService->can($user, $companyId, 'view', $file->folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if (! DriveFileRules::isBrowserViewableFile($file)) {
            return $this->errorResponse(__('api.drive.view_not_available'), 422);
        }

        if (! Storage::disk($file->disk_name)->exists($file->path)) {
            return $this->errorResponse('File not found on disk', 404);
        }

        return $this->fileBinaryResponse($file, true);
    }

    /**
     * @return BinaryFileResponse|JsonResponse
     */
    public function preview(int $id): BinaryFileResponse|JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $file = $this->driveRepository->findFile($companyId, $id);

        if (! $this->driveAccessService->can($user, $companyId, 'view', $file->folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if (! DriveFileRules::isImageFile($file)) {
            return $this->errorResponse('Preview is available for images only', 422);
        }

        if (! Storage::disk($file->disk_name)->exists($file->path)) {
            return $this->errorResponse('File not found on disk', 404);
        }

        return $this->fileBinaryResponse($file, true);
    }

    /**
     * @return JsonResponse
     */
    public function renameFile(RenameDriveFileRequest $request, int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validated();
        $file = $this->driveRepository->findFile($companyId, $id);

        if (! $this->driveAccessService->can($user, $companyId, 'update', $file->folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $name = $this->driveRepository->resolveRenameFileName($file, trim($validated['name']));
        if ($name === '') {
            return $this->errorResponse('Invalid file name', 422);
        }

        $extension = DriveFileRules::extensionFromDriveFile($file);
        if ($extension !== '' && ! DriveFileRules::isAllowedExtension($extension)) {
            return $this->errorResponse('Unsupported file type', 422);
        }

        if ($this->driveRepository->fileNameExists($companyId, $file->folder_id, $name, $file->id)) {
            return $this->errorResponse('File with this name already exists', 422);
        }

        $file = $this->driveRepository->renameFile($file, $name);

        return $this->successResponse(DriveFileResource::make($file)->resolve());
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

        $file = $this->driveRepository->findFile($companyId, $id);

        if (! $this->driveAccessService->can($user, $companyId, 'delete', $file->folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $this->driveRepository->deleteFile($file);

        return $this->successResponse(null);
    }

    /**
     * @return JsonResponse
     */
    public function setPermission(SetDrivePermissionRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validated();
        $folder = null;
        $file = null;

        if ($validated['resource_type'] === DrivePermission::RESOURCE_FOLDER) {
            $folder = $this->driveRepository->findFolder($companyId, (int) $validated['resource_id']);
        } else {
            $file = $this->driveRepository->findFile($companyId, (int) $validated['resource_id']);
            $folder = $file->folder;
        }

        if (! $this->driveAccessService->can($user, $companyId, 'update', $folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $validated['ability'] = $this->driveAccessService->normalizeAclAbility($validated['ability']);
        $validated['effect'] = DrivePermission::EFFECT_ALLOW;
        $validated['subject_type'] = DrivePermission::SUBJECT_USER;

        $abilities = DrivePermission::expandAbilityDependencies(
            [$validated['ability']],
            $validated['resource_type']
        );
        $permission = null;
        foreach ($abilities as $ability) {
            $permission = $this->driveRepository->setPermission($companyId, $user->id, [
                ...$validated,
                'ability' => $ability,
            ]);
        }

        return $this->successResponse(DrivePermissionResource::make($permission)->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function syncPermission(SyncDrivePermissionRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validated();
        $folder = null;
        $file = null;

        if ($validated['resource_type'] === DrivePermission::RESOURCE_FOLDER) {
            $folder = $this->driveRepository->findFolder($companyId, (int) $validated['resource_id']);
        } else {
            $file = $this->driveRepository->findFile($companyId, (int) $validated['resource_id']);
            $folder = $file->folder;
        }

        if (! $this->driveAccessService->can($user, $companyId, 'update', $folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $abilities = array_map(
            static fn (string $ability) => DrivePermission::normalizeAbility($ability),
            $validated['abilities']
        );
        $abilities = DrivePermission::stripDependentAbilitiesWithoutView($abilities, $validated['resource_type']);

        $this->driveRepository->syncSubjectPermissions(
            $companyId,
            $user->id,
            $validated['resource_type'],
            (int) $validated['resource_id'],
            (int) $validated['subject_id'],
            $abilities
        );

        $permissions = $this->driveRepository->listPermissions(
            $companyId,
            $validated['resource_type'],
            (int) $validated['resource_id']
        );

        return $this->successResponse(
            $this->driveRepository->groupPermissionsBySubject($permissions, $validated['resource_type'])
        );
    }

    /**
     * @return JsonResponse
     */
    public function listPermissions(ListDrivePermissionsRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validated();
        $folder = null;
        $file = null;

        if ($validated['resource_type'] === DrivePermission::RESOURCE_FOLDER) {
            $folder = $this->driveRepository->findFolder($companyId, (int) $validated['resource_id']);
        } else {
            $file = $this->driveRepository->findFile($companyId, (int) $validated['resource_id']);
            $folder = $file->folder;
        }

        if (! $this->driveAccessService->can($user, $companyId, 'update', $folder, $file)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $permissions = $this->driveRepository->listPermissions(
            $companyId,
            $validated['resource_type'],
            (int) $validated['resource_id']
        );

        return $this->successResponse(
            $this->driveRepository->groupPermissionsBySubject($permissions, $validated['resource_type'])
        );
    }

    /**
     * @return JsonResponse
     */
    public function moveFiles(MoveDriveFilesRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Company is required', 422);
        }

        $validated = $request->validated();
        $fileIds = array_values(array_unique(array_map('intval', $validated['file_ids'])));
        $targetFolder = $this->driveRepository->findOptionalFolder(
            $companyId,
            ! empty($validated['target_folder_id']) ? (int) $validated['target_folder_id'] : null
        );

        $forbidden = $this->authorizeFilesMove($user, $companyId, $fileIds, $targetFolder);
        if ($forbidden !== null) {
            return $forbidden;
        }

        $moved = $this->driveRepository->moveFilesBatch($companyId, $fileIds, $targetFolder);

        return $this->successResponse(DriveFileResource::collection($moved)->resolve());
    }

    /**
     * @param  array<int, int>  $fileIds
     */
    private function authorizeFilesMove(User $user, int $companyId, array $fileIds, ?DriveFolder $targetFolder): ?JsonResponse
    {
        if ($targetFolder !== null && ! $this->driveAccessService->can($user, $companyId, 'create', $targetFolder)) {
            return $this->errorResponse('Forbidden', 403);
        }

        if ($targetFolder !== null && DriveSystemFolders::isProjectsContainer($targetFolder)) {
            return $this->errorResponse(__('api.drive.system_folder_upload_forbidden'), 422);
        }

        foreach ($fileIds as $fileId) {
            $file = $this->driveRepository->findFile($companyId, $fileId);
            if (! $this->driveAccessService->can($user, $companyId, 'update', $file->folder, $file)) {
                return $this->errorResponse('Forbidden', 403);
            }
        }

        return null;
    }

    /**
     * @return BinaryFileResponse|JsonResponse
     */
    private function fileBinaryResponse(DriveFile $file, bool $inline): BinaryFileResponse
    {
        $absolutePath = Storage::disk($file->disk_name)->path($file->path);
        $mimeType = $file->mime_type ?: 'application/octet-stream';
        $disposition = ($inline ? 'inline' : 'attachment').'; filename="'.str_replace('"', '', $file->name).'"';

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition,
        ]);
    }
}
