<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClientsRepository
{

    /**
     * Получить текущую компанию пользователя из заголовка запроса
     */
    private function getCurrentCompanyId()
    {
        // Получаем company_id из заголовка запроса
        return request()->header('X-Company-ID');
    }

    /**
     * Добавить фильтрацию по компании к запросу
     */
    private function addCompanyFilter($query)
    {
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('clients.company_id', $companyId);
        } else {
            // Если компания не выбрана, показываем только клиентов без company_id (для обратной совместимости)
            $query->whereNull('clients.company_id');
        }
        return $query;
    }

    function getItemsPaginated($perPage = 10, $search = null, $includeInactive = false, $page = 1, $statusFilter = null, $typeFilter = null)
    {
        // Создаем уникальный ключ кэша с учетом компании и фильтров
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "clients_paginated_{$perPage}_{$search}_{$includeInactive}_{$statusFilter}_{$typeFilter}_{$companyId}";

        // Чтение не должно инвалидировать кэш; инвалидация выполняется при CRUD операциях

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $includeInactive, $page, $statusFilter, $typeFilter) {
            // Используем подзапрос для получения баланса, чтобы избежать дублирования
            $query = Client::with(['phones', 'emails', 'user'])
                ->select([
                    'clients.*',
                    DB::raw('(SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance_amount')
                ]);

            // Логируем для отладки
            $companyId = $this->getCurrentCompanyId();

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            // Фильтруем только активных клиентов, если не запрошены неактивные
            if (!$includeInactive) {
                $query->where('clients.status', true);
            }

            // Фильтр по статусу
            if ($statusFilter !== null && $statusFilter !== '') {
                if ($statusFilter === 'active') {
                    $query->where('clients.status', true);
                } elseif ($statusFilter === 'inactive') {
                    $query->where('clients.status', false);
                }
            }

            // Фильтр по типу клиента
            if ($typeFilter !== null && $typeFilter !== '') {
                $query->where('clients.client_type', $typeFilter);
            }

            if ($search) {
                $searchTerm = "%{$search}%";
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('clients.id', 'like', $searchTerm)
                      ->orWhere('clients.first_name', 'like', $searchTerm)
                      ->orWhere('clients.last_name', 'like', $searchTerm)
                      ->orWhere('clients.contact_person', 'like', $searchTerm)
                      ->orWhere('clients.address', 'like', $searchTerm)
                      ->orWhereHas('phones', function ($phoneQuery) use ($searchTerm) {
                          $phoneQuery->where('phone', 'like', $searchTerm);
                      })
                      ->orWhereHas('emails', function ($emailQuery) use ($searchTerm) {
                          $emailQuery->where('email', 'like', $searchTerm);
                      });
                });
            }

            $query->orderBy('clients.created_at', 'desc');

            return $query->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    function searchClient(string $search_request)
    {
        // Кэшируем быстрый поиск по компании (короткий TTL)
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "clients_search_" . md5($search_request) . "_{$companyId}";
        return CacheService::rememberSearch($cacheKey, function () use ($search_request) {
            $searchTerms = explode(' ', $search_request);

            // Используем подзапрос для получения баланса, чтобы избежать дублирования
            $query = Client::with(['phones', 'emails', 'user'])
                ->select([
                    'clients.*',
                    DB::raw('(SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance_amount')
                ])
                ->where('clients.status', true); // Фильтруем только активных клиентов

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            $query->where(function ($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $q->orWhere(function ($subQuery) use ($term) {
                        $subQuery->where('clients.first_name', 'like', "%{$term}%")
                            ->orWhere('clients.last_name', 'like', "%{$term}%")
                            ->orWhere('clients.contact_person', 'like', "%{$term}%")
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

            // Логируем результаты поиска для отладки

            if ($results->count() > 0) {
                foreach ($results as $index => $result) {
                }
            } else {
            }

            return $results;
        });
    }

    public function getItem($id)
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = "client_{$id}_{$companyId}";

        // Чтение не инвалидирует кэш; инвалидация выполняется при CRUD операциях

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            // Используем подзапрос для получения баланса, чтобы избежать дублирования
            $query = Client::with(['phones', 'emails', 'user'])
                ->select([
                    'clients.*',
                    DB::raw('(SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance_amount')
                ])
                ->where('clients.id', $id);

            // Фильтруем по текущей компании пользователя
            $query = $this->addCompanyFilter($query);

            return $query->first();
        });
    }

    public function create(array $data)
    {
        $client = DB::transaction(function () use ($data) {
            $companyId = $this->getCurrentCompanyId();

            $client = Client::create([
                'user_id'        => $data['user_id'] ?? null,
                'company_id'     => $companyId,
                'first_name'     => $data['first_name'],
                'is_conflict'    => $data['is_conflict'] ?? false,
                'is_supplier'    => $data['is_supplier'] ?? false,
                'last_name'      => $data['last_name'] ?? "",
                'contact_person' => $data['contact_person'] ?? null,
                'client_type'    => $data['client_type'],
                'address'        => $data['address'] ?? null,
                'note'           => $data['note'] ?? null,
                'status'         => $data['status'] ?? true,
                'discount' => $data['discount'] ?? 0,
                'discount_type'  => $data['discount_type'] ?? null,
            ]);

            if (!empty($data['phones'])) {
                foreach ($data['phones'] as $phone) {
                    DB::table('clients_phones')->insert([
                        'client_id' => $client->id,
                        'phone'     => $phone,
                    ]);
                }
            }

            if (!empty($data['emails'])) {
                foreach ($data['emails'] as $email) {
                    DB::table('clients_emails')->insert([
                        'client_id' => $client->id,
                        'email'     => $email,
                    ]);
                }
            }

            // Создаем запись баланса для клиента
            DB::table('client_balances')->insert([
                'client_id' => $client->id,
                'balance' => 0.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $client;
        });

        // Инвалидируем кэш клиентов и баланса
        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($client->id);

        return $client;
    }

    /**
     * Создает записи баланса для клиентов, у которых их нет
     */
    public function createMissingBalances()
    {
        $query = DB::table('clients')
            ->leftJoin('client_balances', 'clients.id', '=', 'client_balances.client_id')
            ->whereNull('client_balances.client_id')
            ->select('clients.id');

        // Фильтруем по текущей компании пользователя
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('clients.company_id', $companyId);
        }

        $clientsWithoutBalance = $query->get();

        $created = 0;
        foreach ($clientsWithoutBalance as $client) {
            DB::table('client_balances')->insert([
                'client_id' => $client->id,
                'balance' => 0.00,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        if ($created > 0) {
            // Очищаем кэш клиентов после создания недостающих балансов
            CacheService::invalidateClientsCache();
        }

        return $created;
    }

    public function update($id, array $data)
    {
        $client = DB::transaction(function () use ($id, $data) {
            $client = Client::findOrFail($id);
            $client->update([
                'user_id'        => $data['user_id'] ?? $client->user_id,
                'first_name'     => $data['first_name'],
                'is_conflict'    => $data['is_conflict'] ?? false,
                'is_supplier'    => $data['is_supplier'] ?? false,
                'last_name'      => $data['last_name'] ?? "",
                'contact_person' => $data['contact_person'] ?? null,
                'client_type'    => $data['client_type'],
                'address'        => $data['address'] ?? null,
                'note'           => $data['note'] ?? null,
                'status'         => $data['status'] ?? true,
                'discount' => $data['discount'] ?? 0,
                'discount_type'  => $data['discount_type'] ?? null,
            ]);

            $existingPhones = DB::table('clients_phones')->where('client_id', $client->id)->pluck('phone')->toArray();
            $newPhones = $data['phones'] ?? [];

            $phonesToAdd = array_diff($newPhones, $existingPhones);
            $phonesToRemove = array_diff($existingPhones, $newPhones);

            if (!empty($phonesToAdd)) {
                foreach ($phonesToAdd as $phone) {
                    DB::table('clients_phones')->insert([
                        'client_id' => $client->id,
                        'phone'     => $phone,
                    ]);
                }
            }

            if (!empty($phonesToRemove)) {
                DB::table('clients_phones')->where('client_id', $client->id)->whereIn('phone', $phonesToRemove)->delete();
            }

            $existingEmails = DB::table('clients_emails')->where('client_id', $client->id)->pluck('email')->toArray();
            $newEmails = $data['emails'] ?? [];

            $emailsToAdd = array_diff($newEmails, $existingEmails);
            $emailsToRemove = array_diff($existingEmails, $newEmails);

            if (!empty($emailsToAdd)) {
                foreach ($emailsToAdd as $email) {
                    DB::table('clients_emails')->insert([
                        'client_id' => $client->id,
                        'email'     => $email,
                    ]);
                }
            }

            if (!empty($emailsToRemove)) {
                DB::table('clients_emails')->where('client_id', $client->id)->whereIn('email', $emailsToRemove)->delete();
            }

            return $client;
        });

        // Инвалидируем кэш клиентов и баланса
        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($id);

        return $client;
    }

    function getItemsByIds(array $ids)
    {

        $query = DB::table('clients')
            ->select(
                'clients.id as id',
                'clients.client_type as client_type',
                DB::raw('(SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance'),
                'clients.is_supplier as is_supplier',
                'clients.is_conflict as is_conflict',
                'clients.first_name as first_name',
                'clients.last_name as last_name',
                'clients.contact_person as contact_person',
                'clients.address as address',
                'clients.note as note',
                'clients.status as status',
                'clients.discount_type as discount_type',
                'clients.discount      as discount',
                'clients.created_at as created_at',
                'clients.updated_at as updated_at'
            )
            ->whereIn('clients.id', $ids);

        // Фильтруем по текущей компании пользователя
        $companyId = $this->getCurrentCompanyId();
        if ($companyId) {
            $query->where('clients.company_id', $companyId);
        }

        $clients = $query->get();

        $clientIds = $clients->pluck('id');

        $emails = DB::table('clients_emails')
            ->whereIn('client_id', $clientIds)
            ->select('id', 'client_id', 'email')
            ->get()
            ->groupBy('client_id');

        $phones = DB::table('clients_phones')
            ->whereIn('client_id', $clientIds)
            ->select('id', 'client_id', 'phone')
            ->get()
            ->groupBy('client_id');

        foreach ($clients as $client) {
            $client->emails = $emails->get($client->id, collect());
            $client->phones = $phones->get($client->id, collect());
        }

        return $clients;
    }

    public function getBalanceHistory($clientId)
    {
        $cacheKey = "client_balance_history_{$clientId}";

        return CacheService::remember($cacheKey, function () use ($clientId) {
            try {
                $result = [];

                // Продажи
                if (Schema::hasTable('sales')) {
                    try {
                        $sales = DB::table('sales')
                            ->where('client_id', $clientId)
                            ->select('id', 'created_at', 'total_price', 'cash_id')
                            ->get()
                            ->map(function ($sale) {
                                return [
                                    'source' => 'sale',
                                    'source_id' => $sale->id,
                                    'date' => $sale->created_at,
                                    'amount' => $sale->cash_id ? $sale->total_price : 0,
                                    'description' => $sale->cash_id ? 'Продажа через кассу' : 'Продажа в баланс(долг)'
                                ];
                            });
                    } catch (\Exception $e) {
                        $sales = collect();
                    }
                } else {
                    $sales = collect();
                }

                // Оприходования
                if (Schema::hasTable('warehouse_receipts')) {
                    try {
                        $receipts = DB::table('warehouse_receipts')
                            ->where('supplier_id', $clientId)
                            ->select('id', 'created_at', 'amount', 'cash_id')
                            ->get()
                            ->map(function ($receipt) {
                                return [
                                    'source' => 'receipt',
                                    'source_id' => $receipt->id,
                                    'date' => $receipt->created_at,
                                    'amount' => $receipt->cash_id ? -$receipt->amount : 0,
                                    'description' => $receipt->cash_id ? 'Долг за оприходование(в кассу)' : 'Долг за оприходование(в баланс)'
                                ];
                            });
                    } catch (\Exception $e) {
                        $receipts = collect();
                    }
                } else {
                    $receipts = collect();
                }

                // Транзакции
                if (Schema::hasTable('transactions')) {
                    try {
                        $transactions = DB::table('transactions')
                            ->where('client_id', $clientId)
                            ->select('id', 'created_at', 'orig_amount', 'type')
                            ->get()
                            ->map(function ($tr) {
                                $isIncome = $tr->type === 1;
                                return [
                                    'source' => 'transaction',
                                    'source_id' => $tr->id,
                                    'date' => $tr->created_at,
                                    'amount' => $isIncome ? -$tr->orig_amount : +$tr->orig_amount,
                                    'description' => $isIncome ? 'Клиент оплатил нам' : 'Мы оплатили клиенту'
                                ];
                            });
                    } catch (\Exception $e) {
                        $transactions = collect();
                    }
                } else {
                    $transactions = collect();
                }

                // Заказы
                if (Schema::hasTable('orders')) {
                    try {
                        $orders = DB::table('orders')
                            ->where('client_id', $clientId)
                            ->select('id', 'created_at', 'total_price as amount', 'cash_id')
                            ->get()
                            ->map(function ($order) {
                                return [
                                    'source' => 'order',
                                    'source_id' => $order->id,
                                    'date' => $order->created_at,
                                    'amount' => $order->cash_id ? +$order->amount : 0,
                                    'description' => 'Заказ'
                                ];
                            });
                    } catch (\Exception $e) {
                        $orders = collect();
                    }
                } else {
                    $orders = collect();
                }

                // Объединение и сортировка
                $result = collect()
                    ->merge($sales)
                    ->merge($receipts)
                    ->merge($transactions)
                    ->merge($orders)
                    ->sortBy('date')
                    ->values()
                    ->all();

                return $result;
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    // Получение текущего баланса клиента с кэшированием
    public function getClientBalance($clientId)
    {
        $cacheKey = "client_balance_{$clientId}";

        // Чтение не инвалидирует кэш; инвалидация выполняется при CRUD операциях

        return CacheService::remember($cacheKey, function () use ($clientId) {
            return DB::table('client_balances')
                ->where('client_id', $clientId)
                ->value('balance') ?? 0;
        });
    }

    // Получение балансов нескольких клиентов с кэшированием
    public function getClientsBalances(array $clientIds)
    {
        $cacheKey = "clients_balances_" . md5(implode(',', $clientIds));

        return CacheService::remember($cacheKey, function () use ($clientIds) {
            return DB::table('client_balances')
                ->whereIn('client_id', $clientIds)
                ->pluck('balance', 'client_id')
                ->toArray();
        });
    }

    public function deleteItem($id)
    {
        $result = DB::transaction(function () use ($id) {
            DB::table('clients_emails')->where('client_id', $id)->delete();
            DB::table('clients_phones')->where('client_id', $id)->delete();
            DB::table('client_balances')->where('client_id', $id)->delete();
            return DB::table('clients')->where('id', $id)->delete();
        });

        // Инвалидируем кэш клиентов и баланса
        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($id);

        return $result;
    }

    // Инвалидация кэша баланса клиента
    public function invalidateClientBalanceCache($clientId)
    {
        // Используем новый метод из CacheService
        CacheService::invalidateClientBalanceCache($clientId);
    }

}
