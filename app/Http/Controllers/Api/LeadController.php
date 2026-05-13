<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StoreLeadRequest;
use App\Http\Requests\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Repositories\LeadsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @group Лиды
 */
class LeadController extends BaseController
{
    /**
     * @param  LeadsRepository  $itemsRepository
     */
    public function __construct(protected LeadsRepository $itemsRepository)
    {
    }

    /**
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $userUuid = (int) $this->getAuthenticatedUserIdOrFail();
        $this->authorize('viewAny', Lead::class);

        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $statusId = $request->input('status_id');
        $statusId = $statusId !== null && $statusId !== '' ? (int) $statusId : null;

        $items = $this->itemsRepository->getItemsWithPagination($userUuid, $perPage, $page, $statusId);

        return $this->successResponse([
            'items' => LeadResource::collection($items->items())->resolve(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'next_page' => $items->nextPageUrl(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * @param  int  $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        $this->getAuthenticatedUserIdOrFail();
        $lead = $this->itemsRepository->getItemById((int) $id);
        $this->authorize('view', $lead);

        return $this->successResponse((new LeadResource($lead))->resolve());
    }

    /**
     * @return JsonResponse
     */
    public function store(StoreLeadRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Компания не выбрана', 422);
        }

        $validated = $request->validated();
        $this->itemsRepository->assertClientBelongsToCompany((int) $validated['client_id'], $companyId);

        $payload = [
            'company_id' => $companyId,
            'creator_id' => (int) $user->id,
            'client_id' => (int) $validated['client_id'],
            'title' => $validated['title'] ?? null,
            'lead_source_id' => $validated['lead_source_id'] ?? null,
            'status_id' => $validated['status_id'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'files' => $validated['files'] ?? null,
        ];
        if (array_key_exists('responsible_id', $validated)) {
            $payload['responsible_id'] = $validated['responsible_id'];
        }

        $lead = $this->itemsRepository->createItem($payload, $user);

        return $this->successResponse((new LeadResource($lead))->resolve(), 'Лид создан');
    }

    /**
     * @return JsonResponse
     */
    public function update(UpdateLeadRequest $request, $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Компания не выбрана', 422);
        }

        $validated = $request->validated();
        if (isset($validated['client_id'])) {
            $this->itemsRepository->assertClientBelongsToCompany((int) $validated['client_id'], $companyId);
        }

        $lead = $this->itemsRepository->updateItem((int) $id, $validated, $user);

        return $this->successResponse((new LeadResource($lead))->resolve(), 'Лид сохранён');
    }

    /**
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id): JsonResponse
    {
        $lead = $this->itemsRepository->getItemById((int) $id);
        $this->authorize('delete', $lead);
        $this->itemsRepository->deleteItem((int) $id);

        return $this->successResponse(null, 'Лид удалён');
    }

    /**
     * @return JsonResponse
     */
    public function uploadFiles(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'max:8'],
            'files.*' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,gif,bmp,svg,zip,rar,7z,txt,md'],
        ], [
            'files.*.max' => 'Файл не должен превышать 10MB',
            'files.*.mimes' => 'Неподдерживаемый тип файла',
        ]);

        $files = $request->file('files', []);
        if ($files instanceof \Illuminate\Http\UploadedFile) {
            $files = [$files];
        }
        if (count($files) === 0) {
            return $this->errorResponse('No files uploaded', 400);
        }

        $lead = $this->itemsRepository->getItemById($id);
        $this->authorize('update', $lead);

        $existing = is_array($lead->files) ? $lead->files : [];
        if (count($existing) + count($files) > 50) {
            return $this->errorResponse('Максимум 50 файлов у лида', 400);
        }

        $paths = [];
        foreach ($files as $file) {
            $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
            $paths[] = $file->storeAs('leads/'.$lead->id, $filename, 'public');
        }

        $lead = $this->itemsRepository->appendStoredFilePaths($id, $paths);

        return $this->successResponse((new LeadResource($lead))->resolve(), 'Files uploaded successfully');
    }
}
