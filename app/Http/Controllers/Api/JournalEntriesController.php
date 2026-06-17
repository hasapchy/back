<?php

namespace App\Http\Controllers\Api;

use App\DTO\JournalEntryLineDraft;
use App\Http\Requests\StoreJournalEntryRequest;
use App\Http\Resources\JournalEntryResource;
use App\Repositories\JournalEntriesRepository;
use App\Services\JournalEntryService;
use App\Support\JournalTemplateKeys;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JournalEntriesController extends BaseController
{
    public function __construct(
        private readonly JournalEntriesRepository $repository,
        private readonly JournalEntryService $journalEntryService,
    ) {}

    /**
     * @param  Request  $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $filters = $request->only(['status', 'template_key', 'source_type', 'date_from', 'date_to', 'account_id', 'search']);

        $paginator = $this->repository->paginate($filters, $perPage, $page);

        return $this->successResponse([
            'items' => JournalEntryResource::collection($paginator->items())->resolve(),
            'meta' => $this->paginationMeta($paginator),
        ]);
    }

    /**
     * @param  int  $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        $entry = $this->repository->findForCompany($id);
        if (! $entry) {
            return $this->errorResponse(__('api.common.not_found'), 404);
        }

        return $this->successResponse([
            'item' => (new JournalEntryResource($entry))->resolve(),
        ]);
    }

    /**
     * @param  StoreJournalEntryRequest  $request
     * @return JsonResponse
     */
    public function store(StoreJournalEntryRequest $request): JsonResponse
    {
        $companyId = (int) $this->getCurrentCompanyId();
        $lines = collect($request->input('lines', []))->map(function (array $line): JournalEntryLineDraft {
            return new JournalEntryLineDraft(
                accountCode: (string) $line['account_code'],
                debit: (float) ($line['debit'] ?? 0),
                credit: (float) ($line['credit'] ?? 0),
                meta: is_array($line['meta'] ?? null) ? $line['meta'] : [],
            );
        })->all();

        $entry = $this->journalEntryService->createDraft(
            $companyId,
            Carbon::parse((string) $request->input('entry_date')),
            $request->input('description'),
            JournalTemplateKeys::MANUAL,
            $lines,
            null,
            null,
            [],
        );

        return $this->successResponse([
            'item' => (new JournalEntryResource($entry->load('lines.financialAccount')))->resolve(),
        ], 201);
    }

    /**
     * @param  int  $id
     * @return JsonResponse
     */
    public function post(int $id): JsonResponse
    {
        $entry = $this->repository->findForCompany($id);
        if (! $entry) {
            return $this->errorResponse(__('api.common.not_found'), 404);
        }

        $posted = $this->journalEntryService->post($entry);

        return $this->successResponse([
            'item' => (new JournalEntryResource($posted))->resolve(),
        ]);
    }

    /**
     * @param  int  $id
     * @param  Request  $request
     * @return JsonResponse
     */
    public function reverse(int $id, Request $request): JsonResponse
    {
        $entry = $this->repository->findForCompany($id);
        if (! $entry) {
            return $this->errorResponse(__('api.common.not_found'), 404);
        }

        $reversal = $this->journalEntryService->reverse($entry, $request->input('reason'));

        return $this->successResponse([
            'item' => (new JournalEntryResource($reversal))->resolve(),
        ]);
    }
}
