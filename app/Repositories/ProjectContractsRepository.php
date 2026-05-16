<?php

namespace App\Repositories;

use App\Enums\ProjectContractStatus;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Project;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Services\CacheService;
use App\Services\Timeline\TimelineCache;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Репозиторий для работы с контрактами проектов
 */
class ProjectContractsRepository extends BaseRepository
{
    /**
     * Получить базовый запрос контрактов с валютами
     *
     * @return Builder
     */
    private function getBaseQuery()
    {
        return ProjectContract::select([
            'project_contracts.id',
            'project_contracts.project_id',
            'project_contracts.status',
            'project_contracts.client_id',
            'project_contracts.creator_id',
            'project_contracts.number',
            'project_contracts.type',
            'project_contracts.amount',
            'project_contracts.currency_id',
            'project_contracts.cash_id',
            'project_contracts.client_balance_id',
            'project_contracts.date',
            'project_contracts.returned',
            'project_contracts.paid_amount',
            'project_contracts.files',
            'project_contracts.note',
            'project_contracts.created_at',
            'project_contracts.updated_at',
            'currencies.name as currency_name',
            'currencies.symbol as currency_symbol',
            'cash_registers.name as cash_register_name',
            'cash_registers.icon as cash_register_icon',
            'cash_registers.is_cash as cash_register_is_cash',
            'contract_creator.name as creator_name',
            'contract_creator.photo as creator_photo',
        ])
            ->leftJoin('currencies', 'project_contracts.currency_id', '=', 'currencies.id')
            ->leftJoin('cash_registers', 'project_contracts.cash_id', '=', 'cash_registers.id')
            ->leftJoin('users as contract_creator', 'project_contracts.creator_id', '=', 'contract_creator.id');
    }

    /**
     * Добавить к контрактам payment_status и payment_status_text из полей amount и paid_amount
     *
     * @param  Collection|iterable  $contracts
     * @return Collection|iterable
     */
    private function enrichContractsWithPaymentStatus($contracts)
    {
        $collection = collect($contracts);

        /** @var ProjectContract $contract */
        foreach ($collection as $contract) {
            if ($contract->isDraft()) {
                $contract->payment_status = 'draft';
                $contract->payment_status_text = 'Черновик';

                continue;
            }

            $paidAmount = (float) ($contract->paid_amount ?? 0);
            $amount = (float) ($contract->amount ?? 0);

            $status = 'unpaid';
            $text = 'Не оплачено';

            if ($paidAmount > 0 && $amount > 0) {
                if ($paidAmount >= $amount) {
                    $status = 'paid';
                    $text = 'Оплачено';
                } else {
                    $status = 'partially_paid';

                    $symbol = $contract->currency_symbol ?? '';
                    $formattedPaidAmount = number_format($paidAmount, 2);
                    $amountWithCurrency = trim($formattedPaidAmount.' '.$symbol);

                    $text = $amountWithCurrency !== '' ? 'Частично оплачено: '.$amountWithCurrency : 'Частично оплачено';
                }
            }

            $contract->payment_status = $status;
            $contract->payment_status_text = $text;
        }

        return $contracts;
    }

    /**
     * @param  Collection|iterable  $contracts
     * @return Collection|iterable
     */
    private function normalizeContractCreators($contracts)
    {
        $collection = collect($contracts);

        /** @var object{creator_id: int|null, creator_name: string|null, creator: mixed} $contract */
        foreach ($collection as $contract) {
            $contract->creator = $contract->creator_id ? [
                'id' => (int) $contract->creator_id,
                'name' => $contract->creator_name,
                'photo' => $contract->creator_photo ?? null,
            ] : null;
            unset($contract->creator_name, $contract->creator_photo);
        }

        return $contracts;
    }

    /**
     * @param  Collection|iterable  $contracts
     * @return Collection|iterable
     */
    private function normalizeContractClients($contracts)
    {
        $collection = collect($contracts);

        foreach ($collection as $contract) {
            $first = trim((string) ($contract->client_first_name ?? ''));
            $last = trim((string) ($contract->client_last_name ?? ''));
            $name = trim($first.' '.$last);
            $contract->client_name = $name !== '' ? $name : null;
            $contract->client = ($contract->client_id ?? null)
                ? [
                    'id' => (int) $contract->client_id,
                    'name' => $contract->client_name,
                    'client_type' => $contract->client_type ?? null,
                ]
                : null;
            unset($contract->client_first_name, $contract->client_last_name, $contract->client_type);
        }

        return $contracts;
    }

    /**
     * @param  Collection|iterable  $contracts
     * @return Collection|iterable
     */
    private function normalizeContractCashRegisters($contracts)
    {
        $collection = collect($contracts);

        foreach ($collection as $contract) {
            $contract->cash_register = ($contract->cash_id ?? null)
                ? [
                    'id' => (int) $contract->cash_id,
                    'name' => $contract->cash_register_name ?? null,
                    'icon' => $contract->cash_register_icon ?? null,
                    'is_cash' => (bool) ($contract->cash_register_is_cash ?? false),
                ]
                : null;
            unset($contract->cash_register_icon, $contract->cash_register_is_cash);
        }

        return $contracts;
    }

    /**
     * Aggregated counts for mobile/web list header (respects filters except payment/returned when counting those dimensions).
     *
     * @return list<array{id: string, count: int, color?: string}>
     */
    public function getMetaSectionsForFilters(
        ?string $search = null,
        ?int $projectId = null,
        ?int $cashId = null,
        ?int $type = null,
        bool $activeProjectsOnly = false,
        ?int $projectStatusId = null,
        ?int $userId = null,
        ?string $contractStatus = null,
    ): array {
        $query = $this->buildAllContractsListQuery(
            search: $search,
            projectId: $projectId,
            paymentStatus: null,
            returned: null,
            cashId: $cashId,
            type: $type,
            activeProjectsOnly: $activeProjectsOnly,
            projectStatusId: $projectStatusId,
            userId: $userId,
            contractStatus: $contractStatus,
        );

        $total = (clone $query)->count('project_contracts.id');
        $unpaid = (clone $query)->whereRaw('project_contracts.paid_amount <= 0')->count('project_contracts.id');
        $partiallyPaid = (clone $query)
            ->whereRaw('project_contracts.paid_amount > 0 AND project_contracts.paid_amount < project_contracts.amount')
            ->count('project_contracts.id');
        $paid = (clone $query)
            ->whereRaw('project_contracts.paid_amount >= project_contracts.amount AND project_contracts.amount > 0')
            ->count('project_contracts.id');
        $returned = (clone $query)->where('project_contracts.returned', 1)->count('project_contracts.id');
        $notReturned = (clone $query)->where('project_contracts.returned', 0)->count('project_contracts.id');

        return [
            ['id' => 'total', 'count' => $total],
            ['id' => 'unpaid', 'count' => $unpaid, 'color' => '#EF4444'],
            ['id' => 'partially_paid', 'count' => $partiallyPaid, 'color' => '#F59E0B'],
            ['id' => 'paid', 'count' => $paid, 'color' => '#22C55E'],
            ['id' => 'returned', 'count' => $returned, 'color' => '#22C55E'],
            ['id' => 'not_returned', 'count' => $notReturned, 'color' => '#EF4444'],
        ];
    }

    /**
     * @return Builder
     */
    private function buildAllContractsListQuery(
        ?string $search = null,
        ?int $projectId = null,
        ?string $paymentStatus = null,
        ?bool $returned = null,
        ?int $cashId = null,
        ?int $type = null,
        bool $activeProjectsOnly = false,
        ?int $projectStatusId = null,
        ?int $userId = null,
        ?string $contractStatus = null,
    ) {
        $query = $this->getBaseQuery()
            ->leftJoin('projects', 'project_contracts.project_id', '=', 'projects.id')
            ->leftJoin('clients', 'projects.client_id', '=', 'clients.id')
            ->addSelect(
                'projects.name as project_name',
                'clients.id as client_id',
                'clients.first_name as client_first_name',
                'clients.last_name as client_last_name',
                'clients.client_type as client_type',
            );

        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('projects.company_id', $companyId);
        }

        if ($userId !== null) {
            $query->where('project_contracts.creator_id', $userId);
        }

        if ($activeProjectsOnly) {
            $query->join('project_statuses', 'projects.status_id', '=', 'project_statuses.id')
                ->where('project_statuses.is_visible', true);
        }

        if ($projectStatusId !== null) {
            $query->where('projects.status_id', $projectStatusId);
        }

        if ($projectId) {
            $query->where('project_contracts.project_id', $projectId);
        }

        $this->applyContractStatusFilter($query, $contractStatus);
        $this->applyPaymentStatusFilter($query, $paymentStatus);
        $query->when($returned !== null, function ($q) use ($returned) {
            return $q->where('project_contracts.returned', $returned ? 1 : 0);
        })
            ->when($cashId !== null, function ($q) use ($cashId) {
                return $q->where('project_contracts.cash_id', $cashId);
            })
            ->when($type !== null, function ($q) use ($type) {
                return $q->where('project_contracts.type', $type);
            });

        if ($search) {
            $searchTrimmed = trim((string) $search);
            $searchLower = mb_strtolower($searchTrimmed);
            $query->where(function ($q) use ($searchTrimmed, $searchLower) {
                $q->where('project_contracts.number', 'like', "%{$searchTrimmed}%")
                    ->orWhere('project_contracts.amount', 'like', "%{$searchTrimmed}%")
                    ->orWhere('projects.name', 'like', "%{$searchTrimmed}%")
                    ->orWhereExists(function ($sub) use ($searchTrimmed) {
                        $sub->select(DB::raw(1))
                            ->from('clients')
                            ->whereColumn('clients.id', 'projects.client_id')
                            ->where(function ($cq) use ($searchTrimmed) {
                                $this->applyClientSearchConditions($cq, $searchTrimmed, 'clients');
                            });
                    })
                    ->orWhereExists(function ($phoneSub) use ($searchLower) {
                        $phoneSub->select(DB::raw(1))
                            ->from('clients_phones')
                            ->whereColumn('clients_phones.client_id', 'projects.client_id')
                            ->whereRaw('LOWER(phone) LIKE ?', ["%{$searchLower}%"]);
                    })
                    ->orWhereExists(function ($emailSub) use ($searchLower) {
                        $emailSub->select(DB::raw(1))
                            ->from('clients_emails')
                            ->whereColumn('clients_emails.client_id', 'projects.client_id')
                            ->whereRaw('LOWER(email) LIKE ?', ["%{$searchLower}%"]);
                    });
            });
        }

        return $query;
    }

    /**
     * @param  Builder  $query
     * @param  string|null  $paymentStatus  unpaid|partially_paid|paid
     * @return Builder
     */
    private function applyPaymentStatusFilter($query, $paymentStatus)
    {
        if ($paymentStatus === null) {
            return $query;
        }
        if ($paymentStatus === 'draft') {
            return $query->where('project_contracts.status', ProjectContractStatus::Draft->value);
        }
        if ($paymentStatus === 'paid') {
            return $query->whereRaw('project_contracts.paid_amount >= project_contracts.amount');
        }
        if ($paymentStatus === 'unpaid') {
            return $query->whereRaw('project_contracts.paid_amount <= 0');
        }
        if ($paymentStatus === 'partially_paid') {
            return $query->whereRaw('project_contracts.paid_amount > 0 AND project_contracts.paid_amount < project_contracts.amount');
        }

        return $query;
    }

    /**
     * @param  Builder  $query
     * @param  string|null  $contractStatus  draft|active
     * @return Builder
     */
    private function applyContractStatusFilter($query, $contractStatus)
    {
        if ($contractStatus === null || $contractStatus === '') {
            return $query;
        }

        return $query->where('project_contracts.status', $contractStatus);
    }

    /**
     * Обновить оплаченную сумму контракта на основе транзакций (платежи: is_debt=0)
     *
     * @param  int  $contractId  ID контракта
     */
    public function updateContractPaidAmount(int $contractId): void
    {
        ProjectContract::lockForUpdate()->findOrFail($contractId);

        $paidAmount = Transaction::where('source_type', ProjectContract::class)
            ->where('source_id', $contractId)
            ->where('is_debt', 0)
            ->where('is_deleted', false)
            ->sum('orig_amount');

        ProjectContract::where('id', $contractId)->update([
            'paid_amount' => (float) $paidAmount,
        ]);

        $contract = ProjectContract::find($contractId);
        if ($contract) {
            $this->invalidateProjectContractsCache($contract->project_id, $contractId);
        }
    }

    /**
     * Применить фильтрацию по компании через связь с проектом
     *
     * @param  Builder  $query
     * @param  int|null  $companyId  ID компании (игнорируется, так как в project_contracts нет company_id)
     * @return Builder
     */
    protected function applyCompanyFilter($query, $companyId = null)
    {
        return $query;
    }

    /**
     * Получить контракты проекта с пагинацией
     *
     * @param  int  $projectId  ID проекта
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  string|null  $search  Поисковый запрос
     * @return LengthAwarePaginator
     */
    public function getItemsWithPagination($projectId, $perPage = 20, $page = 1, $search = null)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_paginated', [$projectId, $perPage, $page, $search]);

        return CacheService::getPaginatedData($cacheKey, function () use ($projectId, $perPage, $search, $page) {
            $query = $this->getBaseQuery()
                ->where('project_contracts.project_id', $projectId);

            $this->applyCompanyFilter($query);

            if ($search) {
                $searchTrimmed = trim((string) $search);
                $searchLower = mb_strtolower($searchTrimmed);
                $query->where(function ($q) use ($searchTrimmed, $searchLower) {
                    $q->where('project_contracts.number', 'like', "%{$searchTrimmed}%")
                        ->orWhere('project_contracts.amount', 'like', "%{$searchTrimmed}%")
                        ->orWhereExists(function ($sub) use ($searchTrimmed) {
                            $sub->select(DB::raw(1))
                                ->from('projects')
                                ->whereColumn('projects.id', 'project_contracts.project_id')
                                ->whereExists(function ($clientSub) use ($searchTrimmed) {
                                    $clientSub->select(DB::raw(1))
                                        ->from('clients')
                                        ->whereColumn('clients.id', 'projects.client_id')
                                        ->where(function ($cq) use ($searchTrimmed) {
                                            $this->applyClientSearchConditions($cq, $searchTrimmed, 'clients');
                                        });
                                });
                        })
                        ->orWhereExists(function ($phoneSub) use ($searchLower) {
                            $phoneSub->select(DB::raw(1))
                                ->from('clients_phones')
                                ->join('projects', 'projects.client_id', '=', 'clients_phones.client_id')
                                ->whereColumn('projects.id', 'project_contracts.project_id')
                                ->whereRaw('LOWER(phone) LIKE ?', ["%{$searchLower}%"]);
                        })
                        ->orWhereExists(function ($emailSub) use ($searchLower) {
                            $emailSub->select(DB::raw(1))
                                ->from('clients_emails')
                                ->join('projects', 'projects.client_id', '=', 'clients_emails.client_id')
                                ->whereColumn('projects.id', 'project_contracts.project_id')
                                ->whereRaw('LOWER(email) LIKE ?', ["%{$searchLower}%"]);
                        });
                });
            }

            $paginator = $query->orderBy('project_contracts.id', 'desc')
                ->paginate($perPage, ['*'], 'page', (int) $page);
            $this->enrichContractsWithPaymentStatus($paginator->getCollection());
            $this->normalizeContractCreators($paginator->getCollection());

            return $paginator;
        }, (int) $page);
    }

    /**
     * Получить все контракты проекта
     *
     * @param  int  $projectId  ID проекта
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_all', [$projectId]);

        return CacheService::getReferenceData($cacheKey, function () use ($projectId) {
            $query = $this->getBaseQuery()
                ->where('project_contracts.project_id', $projectId);

            $this->applyCompanyFilter($query);

            $items = $query->orderBy('project_contracts.id', 'desc')
                ->get();
            $this->enrichContractsWithPaymentStatus($items);
            $this->normalizeContractCreators($items);

            return $items;
        });
    }

    /**
     * Получить все контракты с пагинацией (без фильтра по проекту)
     *
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  string|null  $search  Поисковый запрос
     * @param  int|null  $projectId  Фильтр по проекту (опционально)
     * @param  string|null  $paymentStatus  Фильтр по статусу оплаты: unpaid, partially_paid, paid
     * @param  bool|null  $returned  Фильтр по статусу возврата (опционально)
     * @param  int|null  $cashId  Фильтр по кассе (опционально)
     * @param  int|null  $type  Фильтр по типу контракта (опционально)
     * @param  bool  $activeProjectsOnly  Только контракты активных проектов
     * @param  int|null  $projectStatusId  Фильтр по статусу проекта
     * @param  string|null  $contractStatus  Фильтр по статусу контракта: draft|active
     * @return LengthAwarePaginator
     */
    public function getAllContractsWithPagination($perPage = 20, $page = 1, $search = null, $projectId = null, $paymentStatus = null, $returned = null, $cashId = null, $type = null, $activeProjectsOnly = false, $projectStatusId = null, $contractStatus = null)
    {
        $returnedKey = $returned === true ? 'ret1' : ($returned === false ? 'ret0' : 'retn');
        $searchKey = $search !== null ? md5(trim((string) $search)) : 'null';
        $statusKey = $contractStatus ?? 'all';
        $cacheKey = $this->generateCacheKey('all_contracts_paginated', [$perPage, $page, $searchKey, $projectId, $paymentStatus, $returnedKey, $cashId, $type, $activeProjectsOnly, $projectStatusId, $statusKey]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $page, $projectId, $paymentStatus, $returned, $cashId, $type, $activeProjectsOnly, $projectStatusId, $contractStatus) {
            $query = $this->buildAllContractsListQuery(
                search: $search,
                projectId: $projectId,
                paymentStatus: $paymentStatus,
                returned: $returned,
                cashId: $cashId,
                type: $type,
                activeProjectsOnly: $activeProjectsOnly,
                projectStatusId: $projectStatusId,
                contractStatus: $contractStatus,
            );

            $paginator = $query->orderBy('project_contracts.id', 'desc')
                ->paginate($perPage, ['*'], 'page', (int) $page);
            $collection = $paginator->getCollection();
            $this->enrichContractsWithPaymentStatus($collection);
            $this->normalizeContractCreators($collection);
            $this->normalizeContractClients($collection);
            $this->normalizeContractCashRegisters($collection);

            return $paginator;
        }, (int) $page);
    }

    /**
     * Получить все контракты с пагинацией для конкретного пользователя (own)
     *
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  string|null  $search  Поисковый запрос
     * @param  int|null  $projectId  Фильтр по проекту (опционально)
     * @param  int  $userId  ID пользователя
     * @param  string|null  $paymentStatus  Фильтр по статусу оплаты: unpaid, partially_paid, paid
     * @param  bool|null  $returned  Фильтр по статусу возврата (опционально)
     * @param  int|null  $cashId  Фильтр по кассе (опционально)
     * @param  int|null  $type  Фильтр по типу контракта (опционально)
     * @param  bool  $activeProjectsOnly  Только контракты активных проектов
     * @param  int|null  $projectStatusId  Фильтр по статусу проекта
     * @param  string|null  $contractStatus  Фильтр по статусу контракта: draft|active
     * @return LengthAwarePaginator
     */
    public function getAllContractsWithPaginationForUser($perPage, $page, $search, $projectId, $userId, $paymentStatus = null, $returned = null, $cashId = null, $type = null, $activeProjectsOnly = false, $projectStatusId = null, $contractStatus = null)
    {
        $returnedKey = $returned === true ? 'ret1' : ($returned === false ? 'ret0' : 'retn');
        $searchKey = $search !== null ? md5(trim((string) $search)) : 'null';
        $statusKey = $contractStatus ?? 'all';
        $cacheKey = $this->generateCacheKey('all_contracts_paginated_user', [$perPage, $page, $searchKey, $projectId, $userId, $paymentStatus, $returnedKey, $cashId, $type, $activeProjectsOnly, $projectStatusId, $statusKey]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $page, $projectId, $userId, $paymentStatus, $returned, $cashId, $type, $activeProjectsOnly, $projectStatusId, $contractStatus) {
            $query = $this->buildAllContractsListQuery(
                search: $search,
                projectId: $projectId,
                paymentStatus: $paymentStatus,
                returned: $returned,
                cashId: $cashId,
                type: $type,
                activeProjectsOnly: $activeProjectsOnly,
                projectStatusId: $projectStatusId,
                userId: $userId,
                contractStatus: $contractStatus,
            );

            $paginator = $query->orderBy('project_contracts.id', 'desc')
                ->paginate($perPage, ['*'], 'page', (int) $page);
            $collection = $paginator->getCollection();
            $this->enrichContractsWithPaymentStatus($collection);
            $this->normalizeContractCreators($collection);
            $this->normalizeContractClients($collection);
            $this->normalizeContractCashRegisters($collection);

            return $paginator;
        }, (int) $page);
    }

    /**
     * Создать контракт проекта
     *
     * @param  array  $data  Данные контракта
     *
     * @throws \Exception
     */
    public function createContract(array $data): ProjectContract
    {
        return DB::transaction(function () use ($data) {
            $project = Project::findOrFail($data['project_id']);
            $status = isset($data['status'])
                ? ProjectContractStatus::tryFrom((string) $data['status']) ?? ProjectContractStatus::Draft
                : ProjectContractStatus::Draft;

            $contract = new ProjectContract;
            $contract->project_id = $data['project_id'];
            $contract->status = $status;
            $contract->client_id = isset($data['client_id']) && $data['client_id'] !== '' && $data['client_id'] !== null
                ? (int) $data['client_id']
                : ($status === ProjectContractStatus::Active ? $project->client_id : null);
            $contract->creator_id = auth('api')->id();
            $contract->number = $data['number'] ?? null;
            $contract->type = array_key_exists('type', $data) && $data['type'] !== null && $data['type'] !== ''
                ? (int) $data['type']
                : 0;
            $contract->amount = array_key_exists('amount', $data) && $data['amount'] !== null && $data['amount'] !== ''
                ? $data['amount']
                : 0;
            $contract->currency_id = $data['currency_id'] ?? null;
            $contract->cash_id = isset($data['cash_id']) && $data['cash_id'] !== '' && $data['cash_id'] !== null
                ? (int) $data['cash_id']
                : null;
            $contract->client_balance_id = $data['client_balance_id'] ?? null;
            $contract->date = $data['date'] ?? now()->toDateString();
            $contract->returned = $data['returned'] ?? false;
            $contract->paid_amount = 0;
            $contract->files = $data['files'] ?? null;
            $contract->note = $data['note'] ?? null;
            $contract->save();

            if ($status === ProjectContractStatus::Active && $contract->cash_id && ($contract->client_id ?? $project->client_id)) {
                $this->createContractTransaction($contract, $project);
            }

            $this->invalidateProjectContractsCache($data['project_id']);
            TimelineCache::forget('project_contract', (int) $contract->id);

            return $contract;
        });
    }

    /**
     * Обновить контракт проекта
     *
     * @param  int  $id  ID контракта
     * @param  array  $data  Данные для обновления
     *
     * @throws \Exception
     */
    public function updateContract(int $id, array $data): ProjectContract
    {
        return DB::transaction(function () use ($id, $data) {
            $contract = ProjectContract::findOrFail($id);
            $oldProjectId = (int) $contract->project_id;
            $newProjectId = $oldProjectId;
            $wasActive = $contract->isActive();

            if (array_key_exists('status', $data)) {
                $incomingStatus = ProjectContractStatus::tryFrom((string) $data['status']) ?? ProjectContractStatus::Draft;
                if ($wasActive && $incomingStatus === ProjectContractStatus::Draft) {
                    throw new \DomainException(__('Нельзя вернуть активный контракт в черновик.'));
                }
                $contract->status = $incomingStatus;
            }

            if (array_key_exists('project_id', $data)) {
                $newProjectId = (int) $data['project_id'];
                $contract->project_id = $newProjectId;
            }

            $targetProject = Project::query()->select(['id', 'client_id'])->find($newProjectId);
            if (! $targetProject) {
                throw new \DomainException('Проект не найден');
            }

            if (array_key_exists('number', $data)) {
                $contract->number = $data['number'];
            }
            if (array_key_exists('type', $data)) {
                $contract->type = (int) $data['type'];
            }
            if (array_key_exists('client_id', $data)) {
                $contract->client_id = $data['client_id'] !== null && $data['client_id'] !== ''
                    ? (int) $data['client_id']
                    : null;
            }
            if (! array_key_exists('client_id', $data) && $oldProjectId !== $newProjectId) {
                $contract->client_id = $targetProject->client_id ? (int) $targetProject->client_id : null;
            }
            if (array_key_exists('amount', $data)) {
                $contract->amount = $data['amount'];
            }
            if (array_key_exists('currency_id', $data)) {
                $contract->currency_id = $data['currency_id'];
            }
            if (array_key_exists('cash_id', $data)) {
                $contract->cash_id = $data['cash_id'] !== null && $data['cash_id'] !== ''
                    ? (int) $data['cash_id']
                    : null;
            }
            if (array_key_exists('client_balance_id', $data)) {
                $contract->client_balance_id = $data['client_balance_id'] !== null && $data['client_balance_id'] !== ''
                    ? (int) $data['client_balance_id']
                    : null;
            }
            if (array_key_exists('date', $data)) {
                $contract->date = $data['date'];
            }
            if (array_key_exists('note', $data)) {
                $contract->note = $data['note'];
            }

            if (array_key_exists('returned', $data)) {
                $contract->returned = (bool) $data['returned'];
            }

            if (array_key_exists('files', $data)) {
                $contract->files = $data['files'] ?: null;
            }

            $contract->save();

            $contract->loadMissing('project:id,client_id');

            $isActive = $contract->isActive();
            $activating = ! $wasActive && $isActive;

            if ($activating) {
                $hasDebt = Transaction::where('source_type', ProjectContract::class)
                    ->where('source_id', $contract->id)
                    ->where('is_debt', true)
                    ->where('is_deleted', false)
                    ->exists();
                if (! $hasDebt && $contract->cash_id && ($contract->client_id ?? $targetProject->client_id)) {
                    $this->createContractTransaction($contract, $targetProject);
                }
            }

            $debtTransaction = Transaction::where('source_type', ProjectContract::class)
                ->where('source_id', $contract->id)
                ->where('is_debt', true)
                ->where('is_deleted', false)
                ->first();

            if ($isActive && $debtTransaction) {
                $cashRegister = CashRegister::find($contract->cash_id);
                $contractCurrencyId = $contract->currency_id ?? ($cashRegister ? $cashRegister->currency_id : null);
                if (! $contractCurrencyId) {
                    $defaultCurrency = Currency::where('is_default', true)->first();
                    $contractCurrencyId = $defaultCurrency ? $defaultCurrency->id : null;
                }
                $txRepo = app(TransactionsRepository::class);
                $txRepo->updateItem($debtTransaction->id, [
                    'amount' => $contract->amount,
                    'orig_amount' => $contract->amount,
                    'date' => $contract->date,
                    'note' => $contract->note,
                    'cash_id' => $contract->cash_id,
                    'currency_id' => $contractCurrencyId,
                    'client_id' => $contract->client_id ?? $targetProject->client_id,
                    'client_balance_id' => $contract->client_balance_id,
                    'category_id' => $debtTransaction->category_id,
                    'project_id' => $contract->project_id,
                ]);
            }

            $this->invalidateProjectContractsCache($contract->project_id, $id);
            if ($oldProjectId !== (int) $contract->project_id) {
                $this->invalidateProjectContractsCache($oldProjectId);
            }
            TimelineCache::forget('project_contract', $id);

            return $contract;
        });
    }

    /**
     * Найти контракт по ID
     *
     * @param  int  $id  ID контракта
     */
    public function findContract(int $id): ?ProjectContract
    {
        $cacheKey = $this->generateCacheKey('project_contract_item', [$id]);

        return CacheService::remember($cacheKey, function () use ($id) {
            $query = ProjectContract::with([
                'project:id,name,company_id,client_id',
                'project.client:id,first_name,last_name',
                'client:id,first_name,last_name',
                'currency:id,name,symbol',
                'cashRegister:id,name',
                'creator:id,name',
            ])->where('id', $id);

            $this->applyCompanyFilter($query);

            $contract = $query->first();
            if ($contract) {
                $this->enrichContractsWithPaymentStatus(collect([$contract]));
            }

            return $contract;
        }, $this->getCacheTTL('item'));
    }

    /**
     * Удалить контракт проекта
     *
     * @param  int  $id  ID контракта
     *
     * @throws \Exception
     */
    public function deleteContract(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $contract = ProjectContract::findOrFail($id);

            $linkedTransactions = Transaction::where('source_type', ProjectContract::class)
                ->where('source_id', $contract->id)
                ->where('is_deleted', false)
                ->get();

            foreach ($linkedTransactions as $tx) {
                $tx->delete();
            }

            $projectId = $contract->project_id;
            $contract->delete();

            $this->invalidateProjectContractsCache($projectId, $id);
            TimelineCache::forget('project_contract', $id);

            return true;
        });
    }

    /**
     * Создать транзакцию для контракта
     *
     * @param  ProjectContract  $contract  Контракт
     * @param  Project  $project  Проект
     *
     * @throws \Exception
     */
    private function createContractTransaction(ProjectContract $contract, Project $project): void
    {
        if (! $contract->cash_id || ! ($contract->client_id ?? $project->client_id)) {
            return;
        }

        $this->createContractDebtTransaction($contract, $project, auth('api')->id());
    }

    /**
     * Создать долговую транзакцию по контракту (для контрактов без существующей долговой транзакции).
     * Используется при создании контракта и в одноразовой команде contracts:create-debt-transactions.
     *
     * @param  ProjectContract  $contract  Контракт
     * @param  Project  $project  Проект (должен быть загружен с client_id)
     * @param  int|null  $userId  ID пользователя (для консоли; в API — auth)
     * @return bool true если транзакция создана, false если пропущено
     *
     * @throws \Exception
     */
    public function createContractDebtTransaction(ProjectContract $contract, Project $project, ?int $userId = null): bool
    {
        $clientId = $contract->client_id ?? $project->client_id;
        if (! $contract->cash_id || ! $clientId) {
            return false;
        }

        $hasDebt = Transaction::where('source_type', ProjectContract::class)
            ->where('source_id', $contract->id)
            ->where('is_debt', true)
            ->where('is_deleted', false)
            ->exists();

        if ($hasDebt) {
            return false;
        }

        $cashRegister = CashRegister::findOrFail($contract->cash_id);
        $contractCurrencyId = $contract->currency_id ?? $cashRegister->currency_id;

        if (! $contractCurrencyId) {
            $defaultCurrency = Currency::where('is_default', true)->first();
            if (! $defaultCurrency) {
                throw new \Exception('Валюта по умолчанию не найдена');
            }
            $contractCurrencyId = $defaultCurrency->id;
        }

        $this->createTransactionForSource([
            'client_id' => $clientId,
            'amount' => $contract->amount,
            'orig_amount' => $contract->amount,
            'type' => 1,
            'is_debt' => true,
            'cash_id' => $contract->cash_id,
            'category_id' => 30,
            'date' => now(),
            'note' => $contract->note,
            'creator_id' => $userId ?? auth('api')->id(),
            'currency_id' => $contractCurrencyId,
            'project_id' => null,
            'client_balance_id' => $contract->client_balance_id,
        ], ProjectContract::class, $contract->id, true);

        $this->updateContractPaidAmount($contract->id);

        return true;
    }

    /**
     * Инвалидация кэша контрактов проекта
     *
     * @param  int  $projectId  ID проекта
     * @param  int|null  $contractId  ID контракта (опционально, для инвалидации конкретного контракта)
     */
    private function invalidateProjectContractsCache(int $projectId, ?int $contractId = null): void
    {
        if ($contractId !== null) {
            CacheService::forget($this->generateCacheKey('project_contract_item', [$contractId]));
        }

        CacheService::invalidateByLike('%all_contracts_paginated%');
        CacheService::invalidateByLike('%all_contracts_paginated_user%');
        CacheService::invalidateByLike('%project_contract%');

        CacheService::invalidateProjectsCache();
    }
}
