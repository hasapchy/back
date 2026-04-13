<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\ClientsEmail;
use App\Models\ClientsPhone;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CacheService;
use App\Services\ClientBalanceService;
use App\Services\Timeline\TimelineCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientsRepository extends BaseRepository
{
    /**
     * Получить клиентов с пагинацией и фильтрацией
     *
     * @param  int  $perPage  Количество записей на страницу
     * @param  string|null  $search  Поисковый запрос
     * @param  bool  $includeInactive  Включать неактивных клиентов
     * @param  int  $page  Номер страницы
     * @param  string|null  $statusFilter  Фильтр по статусу ('active' или 'inactive')
     * @param  array  $typeFilter  Фильтр по типу клиента
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($perPage = 10, $search = null, $includeInactive = false, $page = 1, $statusFilter = null, $typeFilter = [])
    {
        $typeFilter = $this->normalizeTypeFilter($typeFilter);

        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $typeFilterKey = implode(',', $typeFilter);
        $cacheKey = $this->generateCacheKey('clients_paginated', [$perPage, $search, $includeInactive, $statusFilter, $typeFilterKey, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $includeInactive, $page, $statusFilter, $typeFilter, $currentUser) {
            $query = $this->buildClientListQuery($search, $includeInactive, $statusFilter, $typeFilter);
            $query->orderBy('clients.created_at', 'desc');

            return $query->paginate($perPage, ['*'], 'page', (int) $page);
        }, (int) $page);
    }

    /**
     * Базовый запрос списка клиентов с фильтрами
     *
     * @param  string|null  $search
     * @param  bool  $includeInactive
     * @param  string|null  $statusFilter
     * @param  array  $typeFilter
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function buildClientListQuery($search = null, $includeInactive = false, $statusFilter = null, $typeFilter = [])
    {
        $typeFilter = $this->normalizeTypeFilter($typeFilter);
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $query = Client::with([
            'phones:id,client_id,phone',
            'emails:id,client_id,email',
            'creator:id,name,photo',
            'employee:id,name,surname,position,photo',
            'balances.currency',
            'balances.users',
            'defaultBalance.currency',
        ]);
        $query = $this->addCompanyFilterDirect($query, 'clients');
        $this->applyOwnFilter($query, 'clients', 'clients', 'creator_id', $currentUser, 'employee_id');
        if ($statusFilter) {
            $query->where('clients.status', $statusFilter === 'active');
        } elseif (! $includeInactive) {
            $query->where('clients.status', true);
        }
        if (! empty($typeFilter)) {
            $query->whereIn('clients.client_type', $typeFilter);
        }
        if ($search) {
            $searchTerm = "%{$search}%";
            $query->where(function ($q) use ($searchTerm) {
                $q->where('clients.id', 'like', $searchTerm)
                    ->orWhere('clients.first_name', 'like', $searchTerm)
                    ->orWhere('clients.last_name', 'like', $searchTerm)
                    ->orWhere('clients.position', 'like', $searchTerm)
                    ->orWhere('clients.address', 'like', $searchTerm)
                    ->orWhereHas('phones', function ($phoneQuery) use ($searchTerm) {
                        $phoneQuery->where('phone', 'like', $searchTerm);
                    })
                    ->orWhereHas('emails', function ($emailQuery) use ($searchTerm) {
                        $emailQuery->where('email', 'like', $searchTerm);
                    });
            });
        }

        return $query;
    }

    /**
     * Получить клиентов для экспорта
     *
     * @param  string|null  $search
     * @param  bool  $includeInactive
     * @param  string|null  $statusFilter
     * @param  array  $typeFilter
     * @param  array|null  $ids
     * @param  int  $limit
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getItemsForExport($search = null, $includeInactive = false, $statusFilter = null, $typeFilter = [], ?array $ids = null, int $limit = 10000)
    {
        $query = $this->buildClientListQuery($search, $includeInactive, $statusFilter, $typeFilter);
        $query->when($ids !== null && $ids !== [], fn ($q) => $q->whereIn('clients.id', $ids));
        $query->orderBy('clients.created_at', 'desc');

        return $query->limit($limit)->get();
    }

    /**
     * Получить всех активных клиентов
     *
     * @param  array  $typeFilter
     * @param  bool  $forMutualSettlements
     * @param  string|null  $search
     * @param  bool  $onlyWithBalance
     * @param  int|string|null  $currencyId
     * @param  string|null  $balanceDirection  positive|negative|null
     * @return \Illuminate\Support\Collection
     */
    public function getAllItems(
        array $typeFilter = [],
        bool $forMutualSettlements = false,
        ?string $search = null,
        bool $onlyWithBalance = false,
        $currencyId = null,
        ?string $balanceDirection = null
    )
    {
        $typeFilter = $this->normalizeTypeFilter($typeFilter);

        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();

        if ($forMutualSettlements && $currentUser) {
            $allowedTypes = $this->getAllowedMutualSettlementsClientTypes($currentUser);
            if (empty($allowedTypes)) {
                return collect([]);
            }
            if (empty($typeFilter)) {
                $typeFilter = $allowedTypes;
            } else {
                $typeFilter = array_intersect($typeFilter, $allowedTypes);
            }
        }

        $cacheKey = $this->generateCacheKey('clients_all', [
            $currentUser?->id,
            $companyId,
            implode(',', $typeFilter),
            $forMutualSettlements,
            $search,
            $onlyWithBalance,
            $currencyId,
            $balanceDirection
        ]);

        return CacheService::remember($cacheKey, function () use ($currentUser, $typeFilter, $search, $onlyWithBalance, $currencyId, $balanceDirection) {
            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'creator:id,name,photo',
                'employee:id,name,surname,position,photo',
                'balances.currency',
                'balances.users',
                'defaultBalance.currency',
            ])
                ->where('clients.status', true);

            $query = $this->addCompanyFilterDirect($query, 'clients');

            $this->applyOwnFilter($query, 'clients', 'clients', 'creator_id', $currentUser, 'employee_id');

            if (! empty($typeFilter)) {
                $query->whereIn('clients.client_type', $typeFilter);
            }

            $searchTrimmed = $search !== null ? trim((string) $search) : '';
            if ($searchTrimmed !== '') {
                $searchLower = mb_strtolower($searchTrimmed);
                $query->where(function ($q) use ($searchTrimmed, $searchLower) {
                    $q->whereRaw('LOWER(clients.first_name) LIKE ?', ['%'.$searchLower.'%'])
                        ->orWhereRaw('LOWER(clients.last_name) LIKE ?', ['%'.$searchLower.'%'])
                        ->orWhereHas('phones', function ($pq) use ($searchTrimmed) {
                            $pq->where('phone', 'like', '%'.$searchTrimmed.'%');
                        });
                });
            }

            $direction = in_array($balanceDirection, ['positive', 'negative'], true) ? $balanceDirection : null;
            if ($onlyWithBalance || $direction !== null) {
                $query->whereHas('balances', function ($bq) use ($currencyId, $direction, $onlyWithBalance) {
                    if ($direction === 'positive') {
                        $bq->where('balance', '>', 0);
                    } elseif ($direction === 'negative') {
                        $bq->where('balance', '<', 0);
                    } elseif ($onlyWithBalance) {
                        $bq->where('balance', '!=', 0);
                    }
                    if ((int) $currencyId > 0) {
                        $bq->where('currency_id', (int) $currencyId);
                    }
                });
            }

            $query->orderBy('clients.created_at', 'desc');

            return $query->get();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Получить доступные типы клиентов для просмотра взаиморасчетов
     *
     * @param  \App\Models\User|null  $user  Пользователь
     * @return array Массив типов клиентов, к которым есть доступ
     */
    protected function getAllowedMutualSettlementsClientTypes($user = null)
    {
        if (! $user) {
            return [];
        }

        if ($user->is_admin) {
            return ['individual', 'company', 'employee', 'investor'];
        }

        $permissions = $this->getUserPermissionsForCompany($user);
        $hasViewAll = in_array('mutual_settlements_view_all', $permissions);

        if (! $hasViewAll) {
            return [];
        }

        $allowedTypes = [];
        $clientTypes = ['individual', 'company', 'employee', 'investor'];
        foreach ($clientTypes as $type) {
            if (in_array("mutual_settlements_view_{$type}", $permissions)) {
                $allowedTypes[] = $type;
            }
        }

        return $allowedTypes;
    }

    /**
     * Поиск клиентов по запросу
     *
     * @param  string  $search_request  Поисковый запрос (может содержать несколько слов)
     * @param  array  $typeFilter  Фильтр по типу клиента
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function searchClient(string $search_request, array $typeFilter = [])
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $typeFilterKey = implode(',', $typeFilter);
        $cacheKey = $this->generateCacheKey('clients_search_'.md5($search_request), [$currentUser?->id, $companyId, $typeFilterKey]);

        return CacheService::rememberSearch($cacheKey, function () use ($search_request, $currentUser, $typeFilter) {
            $searchTerms = explode(' ', $search_request);

            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'creator:id,name,photo',
                'employee:id,name,surname,position,photo',
            ])
                ->where('clients.status', true);

            $query = $this->addCompanyFilterDirect($query, 'clients');

            $this->applyOwnFilter($query, 'clients', 'clients', 'creator_id', $currentUser, 'employee_id');

            if (! empty($typeFilter)) {
                $query->whereIn('clients.client_type', $typeFilter);
            }

            $query->where(function ($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $q->orWhere(function ($subQuery) use ($term) {
                        $subQuery->where('clients.first_name', 'like', "%{$term}%")
                            ->orWhere('clients.last_name', 'like', "%{$term}%")
                            ->orWhere('clients.position', 'like', "%{$term}%")
                            ->orWhereHas('phones', function ($phoneQuery) use ($term) {
                                $phoneQuery->where('phone', 'like', "%{$term}%");
                            })
                            ->orWhereHas('emails', function ($emailQuery) use ($term) {
                                $emailQuery->where('email', 'like', "%{$term}%");
                            });
                    });
                }
            });

            $results = $query->limit(50)->get();

            return $results;
        });
    }

    /**
     * Получить клиента по ID
     *
     * @param  int  $id  ID клиента
     * @return Client|null
     */
    public function getItemById($id)
    {
        $cacheKey = $this->generateCacheKey('client', [$id]);

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'creator:id,name,photo',
                'employee:id,name,surname,position,photo',
                'balances.currency',
                'balances.users',
                'defaultBalance.currency',
            ])
                ->where('clients.id', $id);

            $query = $this->addCompanyFilterDirect($query, 'clients');

            return $query->first();
        });
    }

    /**
     * Создать клиента
     *
     * @param  array  $data  Данные клиента
     * @return Client
     */
    public function createItem(array $data)
    {
        $client = DB::transaction(function () use ($data) {
            $companyId = $this->getCurrentCompanyId();

            $client = Client::create([
                'creator_id' => $data['creator_id'] ?? null,
                'company_id' => $companyId,
                'employee_id' => $data['employee_id'] ?? null,
                'first_name' => $data['first_name'],
                'is_conflict' => $data['is_conflict'] ?? false,
                'is_supplier' => $data['is_supplier'] ?? false,
                'last_name' => $data['last_name'] ?? null,
                'patronymic' => $data['patronymic'] ?? null,
                'position' => $data['position'] ?? null,
                'client_type' => $data['client_type'],
                'address' => $data['address'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => $data['status'] ?? true,
                'discount' => $data['discount'] ?? 0,
                'discount_type' => $data['discount_type'] ?? null,
            ]);

            $this->syncPhones($client->id, $data['phones'] ?? []);
            $this->syncEmails($client->id, $data['emails'] ?? []);

            $defaultCurrency = Currency::where('is_default', true)->first();
            if ($defaultCurrency) {
                ClientBalanceService::createBalance($client, $defaultCurrency, true);
            }

            return $client;
        });

        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($client->id);
        TimelineCache::forget('client', (int) $client->id);

        return $client->load('phones', 'emails', 'creator', 'employee', 'balances.currency', 'balances.users', 'defaultBalance.currency');
    }

    /**
     * Обновить клиента
     *
     * @param  int  $id  ID клиента
     * @param  array  $data  Данные для обновления
     * @return Client
     */
    public function updateItem($id, array $data)
    {
        $client = DB::transaction(function () use ($id, $data) {
            $client = Client::findOrFail($id);
            $updateData = [
                'creator_id' => $data['creator_id'] ?? $client->creator_id,
                'employee_id' => $data['employee_id'] ?? $client->employee_id,
                'first_name' => $data['first_name'],
                'is_conflict' => $data['is_conflict'] ?? false,
                'is_supplier' => $data['is_supplier'] ?? false,
                'client_type' => $data['client_type'],
                'status' => $data['status'] ?? true,
                'discount' => $data['discount'] ?? 0,
            ];

            $nullableFields = ['last_name', 'patronymic', 'position', 'address', 'note', 'discount_type'];
            foreach ($nullableFields as $field) {
                if (array_key_exists($field, $data)) {
                    $updateData[$field] = $data[$field];
                } else {
                    $updateData[$field] = $client->$field;
                }
            }

            $client->update($updateData);

            $this->syncPhones($client->id, $data['phones'] ?? []);
            $this->syncEmails($client->id, $data['emails'] ?? []);

            return $client;
        });

        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($id);
        TimelineCache::forget('client', (int) $id);

        return $client->load('phones', 'emails', 'creator', 'employee');
    }

    /**
     * Получить клиентов по массиву ID
     *
     * @param  array  $ids  Массив ID клиентов
     * @return \Illuminate\Support\Collection
     */
    public function getItemsByIds(array $ids)
    {

        $query = Client::whereIn('id', $ids)
            ->with(['employee:id,name,surname,position,photo', 'emails:id,client_id,email', 'phones:id,client_id,phone']);

        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('company_id', $companyId);
        }

            $clients = $query->get()->map(function ($client) {
            return (object) [
                'id' => $client->id,
                'client_type' => $client->client_type,
                'is_supplier' => $client->is_supplier,
                'is_conflict' => $client->is_conflict,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'patronymic' => $client->patronymic,
                'position' => $client->position,
                'address' => $client->address,
                'note' => $client->note,
                'status' => $client->status,
                'discount_type' => $client->discount_type,
                'discount' => $client->discount,
                'employee_id' => $client->employee_id,
                'employee_name' => $client->employee->name ?? null,
                'created_at' => $client->created_at,
                'updated_at' => $client->updated_at,
                'emails' => $client->emails,
                'phones' => $client->phones,
            ];
        });

        return $clients;
    }

    /**
     * Получить историю баланса клиента
     *
     * @param  int  $clientId  ID клиента
     * @param  bool|null  $excludeDebt  Исключить кредитные транзакции (true - только платежи, false/null - все)
     * @param  int|null  $cashRegisterId  ID кассы для фильтрации
     * @param  string|null  $dateFrom  Дата начала периода (формат: Y-m-d)
     * @param  string|null  $dateTo  Дата окончания периода (формат: Y-m-d)
     * @param  int|null  $balanceId  ID баланса клиента
     * @param  int  $page  Номер страницы
     * @param  int  $perPage  Записей на странице
     * @param  string|null  $source  Фильтр по источнику транзакции
     * @param  bool|null  $isDebt  Фильтр по долговым транзакциям (true - только долги)
     * @return array{history: array, current_page: int, last_page: int, total: int, per_page: int}
     */
    public function getBalanceHistory($clientId, $excludeDebt = null, $cashRegisterId = null, $dateFrom = null, $dateTo = null, $balanceId = null, $page = 1, $perPage = 20, $source = null, $isDebt = null)
    {
        $currentUser = auth('api')->user();
        $cacheKey = $this->generateCacheKey('client_balance_history', [$clientId, $excludeDebt, $cashRegisterId, $dateFrom, $dateTo, $balanceId, $page, $perPage, $source, $isDebt, $currentUser?->id]);

        return CacheService::remember($cacheKey, function () use ($clientId, $excludeDebt, $cashRegisterId, $dateFrom, $dateTo, $balanceId, $page, $perPage, $source, $isDebt, $currentUser) {
            try {
                $defaultCurrency = Currency::where('is_default', true)->first();
                $defaultCurrencySymbol = $defaultCurrency?->symbol;

                $clientIdInt = is_string($clientId) ? intval($clientId) : $clientId;

                $transactionsQuery = Transaction::where('client_id', $clientIdInt)
                    ->where('is_deleted', false);

                if ($balanceId) {
                    $balance = \App\Models\ClientBalance::find($balanceId);

                    if ($balance && $balance->client_id == $clientIdInt) {
                        $transactionsQuery->where('client_balance_id', $balanceId);
                    }
                }

                if ($excludeDebt === true) {
                    $transactionsQuery->where('is_debt', false);
                }
                if ($isDebt === true) {
                    $transactionsQuery->where('is_debt', true);
                }

                $permissions = $this->getUserPermissionsForCompany($currentUser);
                $hasBalanceViewAll = in_array('settings_client_balance_view', $permissions);
                $hasBalanceViewOwn = in_array('settings_client_balance_view_own', $permissions);

                $isOwnBalance = false;
                if ($currentUser && $hasBalanceViewOwn && ! $hasBalanceViewAll) {
                    $client = Client::find($clientId);
                    if ($client && $client->employee_id === $currentUser->id) {
                        $isOwnBalance = true;
                    }
                }

                if (! $isOwnBalance && $this->shouldApplyUserFilter('cash_registers')) {
                    $filterUserId = $this->getFilterUserIdForPermission('cash_registers');
                    $transactionsQuery->where(function ($q) use ($filterUserId, $cashRegisterId) {
                        $q->whereNull('cash_id');
                        if ($cashRegisterId) {
                            $q->orWhere(function ($subQ) use ($filterUserId, $cashRegisterId) {
                                $subQ->where('cash_id', $cashRegisterId)
                                    ->whereExists(function ($existsQuery) use ($filterUserId, $cashRegisterId) {
                                        $existsQuery->select(DB::raw(1))
                                            ->from('cash_register_users')
                                            ->where('cash_register_users.cash_register_id', $cashRegisterId)
                                            ->where('cash_register_users.user_id', $filterUserId);
                                    });
                            });
                        } else {
                            $q->orWhereExists(function ($subQuery) use ($filterUserId) {
                                $subQuery->select(DB::raw(1))
                                    ->from('cash_register_users')
                                    ->whereColumn('cash_register_users.cash_register_id', 'transactions.cash_id')
                                    ->where('cash_register_users.user_id', $filterUserId);
                            });
                        }
                    });
                } else {
                    if ($cashRegisterId) {
                        $transactionsQuery->where('cash_id', $cashRegisterId);
                    }
                }

                if ($dateFrom) {
                    $transactionsQuery->whereDate('created_at', '>=', $dateFrom);
                }

                if ($dateTo) {
                    $transactionsQuery->whereDate('created_at', '<=', $dateTo);
                }
                if ($source) {
                    if ($source === 'sale') {
                        $transactionsQuery->where('source_type', 'App\\Models\\Sale');
                    } elseif ($source === 'order') {
                        $transactionsQuery->where('source_type', 'App\\Models\\Order');
                    } elseif ($source === 'receipt') {
                        $transactionsQuery->where('source_type', 'App\\Models\\WhReceipt');
                    } elseif ($source === 'transaction') {
                        $transactionsQuery->where(function ($q) {
                            $q->whereNull('source_type')
                                ->orWhereNotIn('source_type', [
                                    'App\\Models\\Sale',
                                    'App\\Models\\Order',
                                    'App\\Models\\WhReceipt',
                                ]);
                        });
                    }
                }

                $transactionsQuery->with([
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,symbol',
                    'currency:id,symbol',
                    'creator:id,name',
                    'category:id,name',
                    'project:id,name',
                ])
                    ->select(
                        'id',
                        'created_at',
                        'amount',
                        'orig_amount',
                        'type',
                        'source_type',
                        'source_id',
                        'is_debt',
                        'note',
                        'creator_id',
                        'currency_id',
                        'cash_id',
                        'category_id',
                        'client_id',
                        'project_id'
                    );

                $transactionsRepository = app(\App\Repositories\TransactionsRepository::class);
                $transactionsQuery = $transactionsRepository->applySourceTypeFilter($transactionsQuery);
                $transactionsQuery->orderBy('created_at', 'desc');

                $total = $transactionsQuery->count();
                $transactions = $transactionsQuery->skip(($page - 1) * $perPage)->take($perPage)->get();

                $transactions = $transactions->flatMap(function ($item) use ($defaultCurrencySymbol) {
                        $source = 'transaction';
                        if ($item->source_type === 'App\\Models\\Sale') {
                            $source = 'sale';
                        } elseif ($item->source_type === 'App\\Models\\Order') {
                            $source = 'order';
                        } elseif ($item->source_type === 'App\\Models\\WhReceipt') {
                            $source = 'receipt';
                        }
                        $amount = $item->amount;
                        $results = [];

                        $balanceDelta = $this->calculateBalanceDelta($item->type, $item->is_debt, $amount);

                        if ($source === 'receipt') {
                            $receiptId = $item->source_id;

                            if ($item->is_debt) {
                                $description = '📦 Оприходование #'.$receiptId.' (в кредит)';
                                $amount = +$amount;
                            } else {
                                $description = '💰 Оплата поставщику #'.$receiptId;
                                $amount = -$amount;
                            }

                            $results[] = [
                                'source' => $source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => $amount,
                                'balance_delta' => $balanceDelta,
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'creator_id' => $item->creator_id,
                                'creator' => $item->creator ? [
                                    'id' => $item->creator->id,
                                    'name' => $item->creator->name,
                                ] : null,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null,
                                'project_name' => $item->project->name ?? null,
                            ];
                        } elseif ($source === 'transaction') {
                            $transactionId = $item->id;
                            $amount = $item->type == 1 ? +$amount : -$amount;

                            if ($item->is_debt) {
                                $description = $item->type == 1
                                    ? '💸 Кредит клиента #'.$transactionId
                                    : '💸 Наш кредит #'.$transactionId;
                            } else {
                                $description = $item->type == 1
                                    ? '✅ Приход #'.$transactionId
                                    : '🔺 Расход #'.$transactionId;
                            }

                            $results[] = [
                                'source' => $source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => $amount,
                                'balance_delta' => $balanceDelta,
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'creator_id' => $item->creator_id,
                                'creator' => $item->creator ? [
                                    'id' => $item->creator->id,
                                    'name' => $item->creator->name,
                                ] : null,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null,
                                'project_name' => $item->project->name ?? null,
                            ];
                        } elseif ($source === 'sale') {
                            $saleId = $item->source_id;
                            $results[] = [
                                'source' => $source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => +$amount,
                                'balance_delta' => $balanceDelta,
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => '🛒 Продажа #'.$saleId.($item->is_debt ? ' (в кредит)' : ''),
                                'creator_id' => $item->creator_id,
                                'creator' => $item->creator ? [
                                    'id' => $item->creator->id,
                                    'name' => $item->creator->name,
                                ] : null,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null,
                                'project_name' => $item->project->name ?? null,
                            ];
                        } elseif ($source === 'order') {
                            $orderId = $item->source_id;
                            $amount = $item->type == 1 ? +$amount : -$amount;

                            $description = $item->type == 1
                                ? '📋 Заказ #'.$orderId
                                : '💰 Оплата заказа #'.$orderId;

                            $results[] = [
                                'source' => $source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => $amount,
                                'balance_delta' => $balanceDelta,
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'creator_id' => $item->creator_id,
                                'creator' => $item->creator ? [
                                    'id' => $item->creator->id,
                                    'name' => $item->creator->name,
                                ] : null,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null,
                                'project_name' => $item->project->name ?? null,
                            ];
                        } else {
                            $description = $item->note ?? 'Транзакция';
                            $results[] = [
                                'source' => $source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => $amount,
                                'balance_delta' => $balanceDelta,
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'creator_id' => $item->creator_id,
                                'creator' => $item->creator ? [
                                    'id' => $item->creator->id,
                                    'name' => $item->creator->name,
                                ] : null,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null,
                                'project_name' => $item->project->name ?? null,
                            ];
                        }

                        return $results;
                    });

                $orders = collect([]);

                $history = $transactions
                    ->concat($orders)
                    ->sortByDesc('date')
                    ->values()
                    ->all();

                return [
                    'history' => $history,
                    'current_page' => $page,
                    'last_page' => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
                    'total' => $total,
                    'per_page' => $perPage,
                ];
            } catch (\Exception $e) {
                Log::error('Error in getBalanceHistory: '.$e->getMessage(), [
                    'client_id' => $clientId,
                    'exclude_debt' => $excludeDebt,
                    'exception' => $e,
                ]);
                throw $e;
            }
        }, $this->getCacheTTL('reference', true));
    }

    /**
     * @param  mixed  $value
     */
    private function normalizeTypeFilter($value): array
    {
        if (empty($value)) {
            return [];
        }

        $rawValues = is_array($value)
            ? $value
            : explode(',', (string) $value);

        $normalized = array_map(static function ($item) {
            return trim((string) $item);
        }, $rawValues);

        $filtered = array_values(array_filter($normalized, static function ($item) {
            return in_array($item, Client::CLIENT_TYPES, true);
        }));

        return array_values(array_unique($filtered));
    }

    /**
     * Удалить клиента
     *
     * @param  int  $id  ID клиента
     * @return int Количество удаленных записей
     */
    public function deleteItem($id)
    {
        $result = DB::transaction(function () use ($id) {
            ClientsEmail::where('client_id', $id)->delete();
            ClientsPhone::where('client_id', $id)->delete();

            return Client::where('id', $id)->delete();
        });

        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($id);

        return $result;
    }

    /**
     * Синхронизировать телефоны клиента
     *
     * @param  int  $clientId  ID клиента
     * @param  array  $phones  Массив телефонов
     * @return void
     */
    private function syncPhones(int $clientId, array $phones)
    {
        $existingPhones = ClientsPhone::where('client_id', $clientId)
            ->pluck('phone')
            ->toArray();

        $phonesToAdd = array_diff($phones, $existingPhones);
        $phonesToRemove = array_diff($existingPhones, $phones);

        if (! empty($phonesToAdd)) {
            foreach ($phonesToAdd as $phone) {
                ClientsPhone::create([
                    'client_id' => $clientId,
                    'phone' => $phone,
                ]);
            }
        }

        if (! empty($phonesToRemove)) {
            ClientsPhone::where('client_id', $clientId)
                ->whereIn('phone', $phonesToRemove)
                ->delete();
        }
    }

    /**
     * Синхронизировать email клиента
     *
     * @param  int  $clientId  ID клиента
     * @param  array  $emails  Массив email
     * @return void
     */
    private function syncEmails(int $clientId, array $emails)
    {
        $existingEmails = ClientsEmail::where('client_id', $clientId)
            ->pluck('email')
            ->toArray();

        $emailsToAdd = array_diff($emails, $existingEmails);
        $emailsToRemove = array_diff($existingEmails, $emails);

        if (! empty($emailsToAdd)) {
            foreach ($emailsToAdd as $email) {
                ClientsEmail::create([
                    'client_id' => $clientId,
                    'email' => $email,
                ]);
            }
        }

        if (! empty($emailsToRemove)) {
            ClientsEmail::where('client_id', $clientId)
                ->whereIn('email', $emailsToRemove)
                ->delete();
        }
    }

    /**
     * Инвалидировать кэш баланса клиента
     *
     * @param  int  $clientId  ID клиента
     * @return void
     */
    public function invalidateClientBalanceCache($clientId)
    {
        CacheService::invalidateClientBalanceCache($clientId);
        CacheService::invalidateClientBalanceHistoryCache($clientId);
        CacheService::invalidateClientsCache();

        $client = Client::find($clientId);
        if (optional($client)->employee_id) {
            CacheService::invalidateByLike("%user_employee_balance_{$client->employee_id}_%");
        }
    }

    /**
     * Рассчитать дельту баланса клиента для транзакции
     *
     * @param  int  $type  Тип транзакции (0 - расход, 1 - приход)
     * @param  bool  $isDebt  Является ли транзакция кредитной
     * @param  float  $amount  Сумма транзакции
     * @return float Дельта баланса
     */
    private function calculateBalanceDelta(int $type, bool $isDebt, float $amount): float
    {
        if ($amount == 0.0) {
            return 0;
        }

        $sign = $isDebt
            ? ($type === 1 ? 1 : -1)
            : ($type === 1 ? -1 : 1);

        return $sign * $amount;
    }
}
