<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ClientsRepository
{


    function getItemsPaginated($perPage = 20, $search = null, $includeInactive = false, $page = 1)
    {
        // Создаем уникальный ключ кэша
        $cacheKey = "clients_paginated_{$perPage}_{$search}_{$includeInactive}";

        // Принудительно очищаем кэш клиентов перед получением
        CacheService::invalidateClientsCache();

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $includeInactive, $page) {
            // Используем подзапрос для получения баланса, чтобы избежать дублирования
            $query = Client::with(['phones', 'emails', 'user'])
                ->select([
                    'clients.*',
                    DB::raw('(SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance_amount')
                ]);

            // Фильтруем только активных клиентов, если не запрошены неактивные
            if (!$includeInactive) {
                $query->where('clients.status', true);
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

            return $query->paginate($perPage, ['*'], 'page', $page);
        }, $page);
    }

    function searchClient(string $search_request)
    {
        // Временно отключаем кэш для отладки
        // $cacheKey = "clients_search_{$search_request}";
        // CacheService::invalidateClientsCache();
        // return CacheService::getReferenceData($cacheKey, function () use ($search_request) {
            $searchTerms = explode(' ', $search_request);

            // Используем подзапрос для получения баланса, чтобы избежать дублирования
            $query = Client::with(['phones', 'emails', 'user'])
                ->select([
                    'clients.*',
                    DB::raw('(SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance_amount')
                ])
                ->where('clients.status', true); // Фильтруем только активных клиентов

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
            Log::info("=== CLIENT SEARCH BACKEND ===");
            Log::info("Search request: '{$search_request}'");
            Log::info("Search terms: " . json_encode($searchTerms));
            Log::info("Results count: " . $results->count());
            
            if ($results->count() > 0) {
                foreach ($results as $index => $result) {
                    Log::info("Result #{$index}: ID={$result->id}, first_name='{$result->first_name}', last_name='{$result->last_name}', balance={$result->balance_amount}");
                }
            } else {
                Log::info("No results found for search term: '{$search_request}'");
            }
            Log::info("=============================");

            return $results;
        // });
    }

    public function getItem($id)
    {
        $cacheKey = "client_{$id}";

        // Принудительно очищаем кэш для этого клиента перед получением
        Cache::forget($cacheKey);

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            // Используем подзапрос для получения баланса, чтобы избежать дублирования
            return Client::with(['phones', 'emails', 'user'])
                ->select([
                    'clients.*',
                    DB::raw('(SELECT COALESCE(balance, 0) FROM client_balances WHERE client_id = clients.id LIMIT 1) as balance_amount')
                ])
                ->where('clients.id', $id)
                ->first();
        });
    }

    public function create(array $data)
    {
        $client = DB::transaction(function () use ($data) {
            $client = Client::create([
                'user_id'        => $data['user_id'] ?? null,
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
        $clientsWithoutBalance = DB::table('clients')
            ->leftJoin('client_balances', 'clients.id', '=', 'client_balances.client_id')
            ->whereNull('client_balances.client_id')
            ->select('clients.id')
            ->get();

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

        $clients = DB::table('clients')
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
            ->whereIn('clients.id', $ids)
            ->get();

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
                Log::info("Начинаем получение истории баланса для клиента {$clientId}");
                $result = [];

                // Продажи
                Log::info("Проверяем таблицу sales для клиента {$clientId}");
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
                        Log::info("Получено продаж для клиента {$clientId}: " . $sales->count());
                    } catch (\Exception $e) {
                        Log::warning("Ошибка при получении продаж для клиента {$clientId}: " . $e->getMessage());
                        $sales = collect();
                    }
                } else {
                    Log::info("Таблица sales не существует");
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
                        Log::warning("Ошибка при получении оприходований для клиента {$clientId}: " . $e->getMessage());
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
                        Log::warning("Ошибка при получении транзакций для клиента {$clientId}: " . $e->getMessage());
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
                        Log::warning("Ошибка при получении заказов для клиента {$clientId}: " . $e->getMessage());
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
                Log::error("Критическая ошибка при получении истории баланса для клиента {$clientId}: " . $e->getMessage());
                return [];
            }
        });
    }

    // Получение текущего баланса клиента с кэшированием
    public function getClientBalance($clientId)
    {
        $cacheKey = "client_balance_{$clientId}";

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
        // Очищаем кэш баланса конкретного клиента
        CacheService::invalidateByTag("client_balance_{$clientId}");
        CacheService::invalidateByTag("client_balance_history_{$clientId}");
    }

}
