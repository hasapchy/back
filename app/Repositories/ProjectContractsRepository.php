<?php

namespace App\Repositories;

use App\Models\ProjectContract;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\Currency;
use App\Models\CashRegister;
use App\Repositories\TransactionsRepository;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

/**
 * Репозиторий для работы с контрактами проектов
 */
class ProjectContractsRepository extends BaseRepository
{
    /**
     * Получить базовый запрос контрактов с валютами
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    private function getBaseQuery()
    {
        return ProjectContract::select([
            'project_contracts.id',
            'project_contracts.project_id',
            'project_contracts.number',
            'project_contracts.amount',
            'project_contracts.currency_id',
            'project_contracts.cash_id',
            'project_contracts.date',
            'project_contracts.returned',
            'project_contracts.is_paid',
            'project_contracts.files',
            'project_contracts.note',
            'project_contracts.created_at',
            'project_contracts.updated_at',
            'currencies.name as currency_name',
            'currencies.symbol as currency_symbol',
            'cash_registers.name as cash_register_name'
        ])
            ->leftJoin('currencies', 'project_contracts.currency_id', '=', 'currencies.id')
            ->leftJoin('cash_registers', 'project_contracts.cash_id', '=', 'cash_registers.id');
    }

    /**
     * Применить фильтрацию по компании через связь с проектом
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param int|null $companyId ID компании (игнорируется, так как в project_contracts нет company_id)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function applyCompanyFilter($query, $companyId = null)
    {
        return $query;
    }

    /**
     * Получить контракты проекта с пагинацией
     *
     * @param int $projectId ID проекта
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @param string|null $search Поисковый запрос
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($projectId, $perPage = 20, $page = 1, $search = null)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_paginated', [$projectId, $perPage, $page, $search]);

        return CacheService::getPaginatedData($cacheKey, function () use ($projectId, $perPage, $search, $page) {
            $query = $this->getBaseQuery()
                ->where('project_contracts.project_id', $projectId);

            $this->applyCompanyFilter($query);

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('project_contracts.number', 'like', "%{$search}%")
                        ->orWhere('project_contracts.amount', 'like', "%{$search}%");
                });
            }

            return $query->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    /**
     * Получить все контракты проекта
     *
     * @param int $projectId ID проекта
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($projectId)
    {
        $cacheKey = $this->generateCacheKey('project_contracts_all', [$projectId]);

        return CacheService::getReferenceData($cacheKey, function () use ($projectId) {
            $query = $this->getBaseQuery()
                ->where('project_contracts.project_id', $projectId);

            $this->applyCompanyFilter($query);

            return $query->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->get();
        });
    }

    /**
     * Получить все контракты с пагинацией (без фильтра по проекту)
     *
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @param string|null $search Поисковый запрос
     * @param int|null $projectId Фильтр по проекту (опционально)
     * @param bool|null $isPaid Фильтр по статусу оплаты (опционально)
     * @param bool|null $returned Фильтр по статусу возврата (опционально)
     * @param int|null $cashId Фильтр по кассе (опционально)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getAllContractsWithPagination($perPage = 20, $page = 1, $search = null, $projectId = null, $isPaid = null, $returned = null, $cashId = null)
    {
        // Формируем ключ кэша с явными префиксами для фильтров, чтобы избежать коллизий
        $isPaidKey = $isPaid === true ? 'paid1' : ($isPaid === false ? 'paid0' : 'paidn');
        $returnedKey = $returned === true ? 'ret1' : ($returned === false ? 'ret0' : 'retn');
        $cacheKey = $this->generateCacheKey('all_contracts_paginated', [$perPage, $page, $search, $projectId, $isPaidKey, $returnedKey, $cashId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $page, $projectId, $isPaid, $returned, $cashId) {
            $query = $this->getBaseQuery()
                ->leftJoin('projects', 'project_contracts.project_id', '=', 'projects.id')
                ->addSelect('projects.name as project_name', 'projects.id as project_id');

            $companyId = $this->getCurrentCompanyId();
            if ($companyId) {
                $query->where('projects.company_id', $companyId);
            }

            if ($projectId) {
                $query->where('project_contracts.project_id', $projectId);
            }

            if ($isPaid !== null) {
                $query->where('project_contracts.is_paid', $isPaid ? 1 : 0);
            }

            if ($returned !== null) {
                $query->where('project_contracts.returned', $returned ? 1 : 0);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('project_contracts.number', 'like', "%{$search}%")
                        ->orWhere('project_contracts.amount', 'like', "%{$search}%")
                        ->orWhere('projects.name', 'like', "%{$search}%");
                });
            }

            return $query->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    /**
     * Получить все контракты с пагинацией для конкретного пользователя (own)
     *
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @param string|null $search Поисковый запрос
     * @param int|null $projectId Фильтр по проекту (опционально)
     * @param int $userId ID пользователя
     * @param bool|null $isPaid Фильтр по статусу оплаты (опционально)
     * @param bool|null $returned Фильтр по статусу возврата (опционально)
     * @param int|null $cashId Фильтр по кассе (опционально)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getAllContractsWithPaginationForUser($perPage = 20, $page = 1, $search = null, $projectId = null, $userId, $isPaid = null, $returned = null, $cashId = null)
    {
        // Формируем ключ кэша с явными префиксами для фильтров, чтобы избежать коллизий
        $isPaidKey = $isPaid === true ? 'paid1' : ($isPaid === false ? 'paid0' : 'paidn');
        $returnedKey = $returned === true ? 'ret1' : ($returned === false ? 'ret0' : 'retn');
        $cacheKey = $this->generateCacheKey('all_contracts_paginated_user', [$perPage, $page, $search, $projectId, $userId, $isPaidKey, $returnedKey, $cashId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $page, $projectId, $userId, $isPaid, $returned, $cashId) {
            $query = $this->getBaseQuery()
                ->leftJoin('projects', 'project_contracts.project_id', '=', 'projects.id')
                ->addSelect('projects.name as project_name', 'projects.id as project_id')
                ->where('projects.user_id', $userId);

            $companyId = $this->getCurrentCompanyId();
            if ($companyId) {
                $query->where('projects.company_id', $companyId);
            }

            if ($projectId) {
                $query->where('project_contracts.project_id', $projectId);
            }

            if ($isPaid !== null) {
                $query->where('project_contracts.is_paid', $isPaid ? 1 : 0);
            }

            if ($returned !== null) {
                $query->where('project_contracts.returned', $returned ? 1 : 0);
            }

            if ($cashId !== null) {
                $query->where('project_contracts.cash_id', $cashId);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('project_contracts.number', 'like', "%{$search}%")
                        ->orWhere('project_contracts.amount', 'like', "%{$search}%")
                        ->orWhere('projects.name', 'like', "%{$search}%");
                });
            }

            return $query->orderBy('project_contracts.date', 'desc')
                ->orderBy('project_contracts.created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    /**
     * Создать контракт проекта
     *
     * @param array $data Данные контракта
     * @return ProjectContract
     * @throws \Exception
     */
    public function createContract(array $data): ProjectContract
    {
        return DB::transaction(function () use ($data) {
            $project = Project::findOrFail($data['project_id']);

            $contract = new ProjectContract();
            $contract->project_id = $data['project_id'];
            $contract->creator_id = auth('api')->id();
            $contract->number = $data['number'];
            $contract->amount = $data['amount'];
            $contract->currency_id = $data['currency_id'] ?? null;
            $contract->cash_id = $data['cash_id'] ?? null;
            $contract->date = $data['date'];
            $contract->returned = $data['returned'] ?? false;
            $contract->is_paid = $data['is_paid'] ?? false;
            $contract->files = $data['files'] ?? [];
            $contract->note = $data['note'] ?? null;
            $contract->save();

            if ($contract->is_paid && $contract->cash_id && $project->client_id) {
                $this->createContractTransaction($contract, $project);
            }

            $this->invalidateProjectContractsCache($data['project_id']);

            return $contract;
        });
    }

    /**
     * Обновить контракт проекта
     *
     * @param int $id ID контракта
     * @param array $data Данные для обновления
     * @return ProjectContract
     * @throws \Exception
     */
    public function updateContract(int $id, array $data): ProjectContract
    {
        return DB::transaction(function () use ($id, $data) {
            $contract = ProjectContract::findOrFail($id);
            $project = $contract->project;

            $wasPaid = (bool) $contract->is_paid;

            if ($wasPaid && array_key_exists('cash_id', $data) && (int) $contract->cash_id !== (int) ($data['cash_id'] ?? null)) {
                throw new \DomainException('Нельзя изменить кассу для оплаченного контракта. Контракт уже отмечен как оплаченный.');
            }

            $contract->number = $data['number'];
            $contract->amount = $data['amount'];
            $contract->currency_id = $data['currency_id'] ?? null;
            $contract->cash_id = $data['cash_id'] ?? null;
            $contract->date = $data['date'];
            $contract->note = $data['note'] ?? null;

            if (array_key_exists('returned', $data)) {
                $contract->returned = (bool) $data['returned'];
            }
            
            if (array_key_exists('is_paid', $data)) {
                $isPaid = (bool) $data['is_paid'];
                if ($wasPaid === true && $isPaid === false) {
                    throw new \DomainException('Нельзя снять отметку об оплате. Контракт уже отмечен как оплаченный, это действие необратимо.');
                }
                $contract->is_paid = $isPaid;
            }

            if (isset($data['files']) && is_array($data['files'])) {
                $contract->files = $data['files'];
            }

            $contract->save();

            $contractTransaction = Transaction::where('source_type', ProjectContract::class)
                ->where('source_id', $contract->id)
                ->where('type', 1)
                ->where('is_debt', true)
                ->where('is_deleted', false)
                ->first();

            if ($contract->is_paid && $contract->cash_id && $project->client_id) {
                if ($contractTransaction) {
                    $transactionNeedsUpdate = $contractTransaction->amount != $contract->amount
                        || (int) $contractTransaction->client_id !== (int) $project->client_id
                        || (int) $contractTransaction->project_id !== (int) $contract->project_id
                        || (int) $contractTransaction->cash_id !== (int) $contract->cash_id
                        || $contractTransaction->date != $contract->date
                        || $contractTransaction->note !== $contract->note;

                    if ($transactionNeedsUpdate) {
                        $txRepo = new TransactionsRepository();
                        $txRepo->updateItem($contractTransaction->id, [
                            'amount' => $contract->amount,
                            'orig_amount' => $contract->amount,
                            'client_id' => $project->client_id,
                            'project_id' => $contract->project_id,
                            'cash_id' => $contract->cash_id,
                            'category_id' => 1,
                            'date' => $contract->date,
                            'note' => $contract->note,
                        ]);
                    }
                } else if (!$wasPaid) {
                    $this->createContractTransaction($contract, $project);
                }
            } else if ($contractTransaction) {
                $contractTransaction->delete();
            }

            $this->invalidateProjectContractsCache($contract->project_id, $id);

            return $contract;
        });
    }

    /**
     * Найти контракт по ID
     *
     * @param int $id ID контракта
     * @return ProjectContract|null
     */
    public function findContract(int $id): ?ProjectContract
    {
        $cacheKey = $this->generateCacheKey('project_contract_item', [$id]);

        return CacheService::remember($cacheKey, function () use ($id) {
            $query = ProjectContract::with([
                'project:id,name,company_id',
                'currency:id,name,symbol',
                'cashRegister:id,name'
            ])->where('id', $id);

            $this->applyCompanyFilter($query);

            return $query->first();
        }, $this->getCacheTTL('item'));
    }

    /**
     * Удалить контракт проекта
     *
     * @param int $id ID контракта
     * @return bool
     * @throws \Exception
     */
    public function deleteContract(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $contract = ProjectContract::findOrFail($id);

            $contractTransaction = Transaction::where('source_type', ProjectContract::class)
                ->where('source_id', $contract->id)
                ->where('is_deleted', false)
                ->first();

            if ($contractTransaction) {
                $contractTransaction->delete();
            }

            $projectId = $contract->project_id;
            $contract->delete();

            $this->invalidateProjectContractsCache($projectId, $id);

            return true;
        });
    }

    /**
     * Создать транзакцию для контракта
     *
     * @param ProjectContract $contract Контракт
     * @param Project $project Проект
     * @return void
     * @throws \Exception
     */
    private function createContractTransaction(ProjectContract $contract, Project $project): void
    {
        if (!$contract->cash_id || !$project->client_id) {
            return;
        }

        $cashRegister = CashRegister::findOrFail($contract->cash_id);
        $contractCurrencyId = $contract->currency_id ?? $cashRegister->currency_id;
        
        if (!$contractCurrencyId) {
            $defaultCurrency = Currency::where('is_default', true)->first();
            if (!$defaultCurrency) {
                throw new \Exception('Валюта по умолчанию не найдена');
            }
            $contractCurrencyId = $defaultCurrency->id;
        }

        $this->createTransactionForSource([
            'client_id'    => $project->client_id,
            'amount'       => $contract->amount,
            'orig_amount'  => $contract->amount,
            'type'         => 1,
            'is_debt'      => true,
            'cash_id'      => $contract->cash_id,
            'category_id'  => 1,
            'date'         => $contract->date,
            'note'         => $contract->note,
            'user_id'      => auth('api')->id(),
            'project_id'   => $contract->project_id,
            'currency_id'  => $contractCurrencyId,
        ], ProjectContract::class, $contract->id, true);
    }

    /**
     * Инвалидация кэша контрактов проекта
     *
     * @param int $projectId ID проекта
     * @param int|null $contractId ID контракта (опционально, для инвалидации конкретного контракта)
     * @return void
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
