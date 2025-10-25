<?php

namespace App\Repositories;

use App\Models\Client;
use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
            // Используем простую колонку balance вместо сложных вычислений
            $query = Client::with(['phones', 'emails', 'user', 'employee'])
                ->select([
                    'clients.*',
                    'clients.balance as balance'
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

            // Используем простую колонку balance вместо сложных вычислений
            $query = Client::with(['phones', 'emails', 'user', 'employee'])
                ->select([
                    'clients.*',
                    'clients.balance as balance'
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
            // Используем простую колонку balance вместо сложных вычислений
            $query = Client::with(['phones', 'emails', 'user', 'employee'])
                ->select([
                    'clients.*',
                    'clients.balance as balance'
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
                'employee_id'    => $data['employee_id'] ?? null,
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

            // Колонка clients.balance по умолчанию = 0

            return $client;
        });

        // Инвалидируем кэш клиентов и баланса
        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($client->id);

        return $client->load('phones', 'emails', 'user', 'employee');
    }

    /**
     * Создает записи баланса для клиентов, у которых их нет
     */
    // createMissingBalances больше не требуется (баланс в clients.balance)

    public function update($id, array $data)
    {
        $client = DB::transaction(function () use ($id, $data) {
            $client = Client::findOrFail($id);
            $client->update([
                'user_id'        => $data['user_id'] ?? $client->user_id,
                'employee_id'    => $data['employee_id'] ?? $client->employee_id,
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

        return $client->load('phones', 'emails', 'user', 'employee');
    }

    function getItemsByIds(array $ids)
    {

        $query = DB::table('clients')
            ->leftJoin('users', 'clients.employee_id', '=', 'users.id')
            ->select(
                'clients.id as id',
                'clients.client_type as client_type',
                'clients.balance as balance',
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
                'clients.employee_id as employee_id',
                'users.name as employee_name',
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
                // Получаем валюту по умолчанию для конвертации
                $defaultCurrency = \App\Models\Currency::where('is_default', true)->first();
                $defaultCurrencySymbol = $defaultCurrency ? $defaultCurrency->symbol : '';

                // Новая архитектура: все операции записываются в таблицу transactions с morphable связями
                $transactions = DB::table('transactions')
                    ->leftJoin('cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
                    ->leftJoin('currencies', 'transactions.currency_id', '=', 'currencies.id')
                    ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
                    ->where('transactions.client_id', $clientId)
                    ->select(
                        'transactions.id',
                        'transactions.created_at',
                        'transactions.amount',
                        'transactions.orig_amount',
                        'transactions.type',
                        'transactions.source_type',
                        'transactions.source_id',
                        'transactions.is_debt',
                        'transactions.note',
                        'transactions.user_id',
                        'users.name as user_name',
                        DB::raw("CASE
                            WHEN transactions.source_type = 'App\\\\Models\\\\Sale' THEN 'sale'
                            WHEN transactions.source_type = 'App\\\\Models\\\\Order' THEN 'order'
                            WHEN transactions.source_type = 'App\\\\Models\\\\WarehouseReceipt' THEN 'receipt'
                            ELSE 'transaction'
                        END as source"),
                        'currencies.symbol as currency_symbol',
                        'cash_registers.name as cash_name'
                    )
                    ->get()
                    ->flatMap(function ($item) use ($defaultCurrencySymbol) {
                        $amount = $item->amount;
                        $results = []; // Массив результатов (может быть 1 или 2 записи для недолговых операций)

                        // История баланса показывает движение товаров/денег с точки зрения долга
                        if ($item->source === 'receipt') {
                            // Оприходование от поставщика
                            $receiptId = $item->source_id;

                            // Определяем описание и знак по is_debt
                            if ($item->is_debt) {
                                // Долговая транзакция: поставщик нам должен (долг увеличивается)
                                $description = '📦 Оприходование #' . $receiptId . ' (в кредит)';
                                $amount = +$amount; // Положительный - долг растет
                            } else {
                                // Обычная транзакция: мы платим поставщику (долг уменьшается)
                                $description = '💰 Оплата поставщику #' . $receiptId;
                                $amount = -$amount; // Отрицательный - платим деньги
                            }

                            $results[] = [
                                'source' => $item->source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => $amount,
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'user_id' => $item->user_id,
                                'user_name' => $item->user_name,
                                'currency_symbol' => $item->currency_symbol,
                                'cash_name' => $item->cash_name
                            ];
                        } elseif ($item->source === 'transaction') {
                            // Обычная транзакция: type=1 (приход), type=0 (расход)
                            $transactionId = $item->id;
                            $amount = $item->type == 1 ? +$amount : -$amount;

                            // Формируем описание с явным указанием типа
                            if ($item->is_debt) {
                                $description = $item->type == 1
                                    ? '💸 Кредит клиента #' . $transactionId
                                    : '💸 Наш кредит #' . $transactionId;
                            } else {
                                $description = $item->type == 1
                                    ? '✅ Приход #' . $transactionId
                                    : '🔺 Расход #' . $transactionId;
                            }

                            $results[] = [
                                'source' => $item->source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => $amount, // type=1 → плюс, type=0 → минус
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'user_id' => $item->user_id,
                                'user_name' => $item->user_name,
                                'currency_symbol' => $item->currency_symbol,
                                'cash_name' => $item->cash_name
                            ];
                        } elseif ($item->source === 'sale') {
                            // Продажа (type=1 всегда - приход)
                            $saleId = $item->source_id;
                            $results[] = [
                                'source' => $item->source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => +$amount, // type=1 → плюс (приход)
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => '🛒 Продажа #' . $saleId . ($item->is_debt ? ' (в кредит)' : ''),
                                'user_id' => $item->user_id,
                                'user_name' => $item->user_name,
                                'currency_symbol' => $item->currency_symbol,
                                'cash_name' => $item->cash_name
                            ];
                        } elseif ($item->source === 'order') {
                            // Заказ: type=1 (приход - создание), type=0 (расход - оплата)
                            $orderId = $item->source_id;
                            $amount = $item->type == 1 ? +$amount : -$amount;

                            $description = $item->type == 1
                                ? '📋 Заказ #' . $orderId
                                : '💰 Оплата заказа #' . $orderId;

                            $results[] = [
                                'source' => $item->source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => $amount, // type=1 → плюс, type=0 → минус
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'user_id' => $item->user_id,
                                'user_name' => $item->user_name,
                                'currency_symbol' => $item->currency_symbol,
                                'cash_name' => $item->cash_name
                            ];
                        } else {
                            $description = $item->note ?? 'Транзакция';
                            $results[] = [
                                'source' => $item->source,
                                'source_id' => $item->id,
                                'source_type' => $item->source_type,
                                'source_source_id' => $item->source_id,
                                'date' => $item->created_at,
                                'amount' => $amount,
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'user_id' => $item->user_id,
                                'user_name' => $item->user_name,
                                'currency_symbol' => $item->currency_symbol,
                                'cash_name' => $item->cash_name
                            ];
                        }

                        return $results; // Возвращаем массив (1 или 2 записи)
                    });

                // Заказы теперь создают автоматические транзакции (type=1, is_debt=true, source_type=Order)
                // Поэтому НЕ добавляем их отдельно - они уже есть в $transactionsResult выше
                $orders = collect([]);

                // Объединяем транзакции и заказы, сортируем по дате
                $result = $transactions
                    ->concat($orders)
                    ->sortBy('date')
                    ->values()
                    ->all();

                return $result;
            } catch (\Exception $e) {
                return [];
            }
        }, 900); // 15 минут
    }


    public function deleteItem($id)
    {
        $result = DB::transaction(function () use ($id) {
            DB::table('clients_emails')->where('client_id', $id)->delete();
            DB::table('clients_phones')->where('client_id', $id)->delete();
            // Колонка clients.balance остаётся, удаляем только связанные записи
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
        // Инвалидируем кэш конкретного клиента (включая баланс)
        CacheService::invalidateClientBalanceCache($clientId);

        // Также инвалидируем список всех клиентов (чтобы Store обновился)
        CacheService::invalidateClientsCache();
    }

}
