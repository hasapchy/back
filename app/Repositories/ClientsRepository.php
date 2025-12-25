<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\ClientsEmail;
use App\Models\ClientsPhone;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientsRepository extends BaseRepository
{
    /**
     * Получить клиентов с пагинацией и фильтрацией
     *
     * @param int $perPage Количество записей на страницу
     * @param string|null $search Поисковый запрос
     * @param bool $includeInactive Включать неактивных клиентов
     * @param int $page Номер страницы
     * @param string|null $statusFilter Фильтр по статусу ('active' или 'inactive')
     * @param array $typeFilter Фильтр по типу клиента
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
            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'user:id,name,photo',
                'employee:id,name,surname,position,photo'
            ])
                ->select([
                    'clients.*',
                    'clients.balance as balance'
                ]);

            $query = $this->addCompanyFilterDirect($query, 'clients');

            $this->applyOwnFilter($query, 'clients', 'clients', 'user_id', $currentUser);

            if ($statusFilter) {
                $query->where('clients.status', $statusFilter === 'active');
            } elseif (!$includeInactive) {
                $query->where('clients.status', true);
            }

            if (!empty($typeFilter)) {
                $query->whereIn('clients.client_type', $typeFilter);
            }

            if ($search) {
                $searchTerm = "%{$search}%";
                $query->where(function ($q) use ($searchTerm) {
                    $q->where('clients.id', 'like', $searchTerm)
                      ->orWhere('clients.first_name', 'like', $searchTerm)
                      ->orWhere('clients.last_name', 'like', $searchTerm)
                      ->orWhere('clients.contact_person', 'like', $searchTerm)
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

            $query->orderBy('clients.created_at', 'desc');

            return $query->paginate($perPage, ['*'], 'page', (int)$page);
        }, (int)$page);
    }

    /**
     * Получить всех активных клиентов
     *
     * @param array $typeFilter
     * @param bool $forMutualSettlements Применять фильтр по правам доступа к взаиморасчетам
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems(array $typeFilter = [], bool $forMutualSettlements = false)
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

        $cacheKey = $this->generateCacheKey('clients_all', [$currentUser?->id, $companyId, implode(',', $typeFilter), $forMutualSettlements]);

        return CacheService::remember($cacheKey, function () use ($currentUser, $typeFilter) {
            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'user:id,name,photo',
                'employee:id,name,surname,position,photo'
            ])
                ->select([
                    'clients.id',
                    'clients.company_id',
                    'clients.user_id',
                    'clients.client_type',
                    'clients.balance',
                    'clients.is_supplier',
                    'clients.first_name',
                    'clients.last_name',
                    'clients.patronymic',
                    'clients.contact_person',
                    'clients.position',
                    'clients.employee_id'
                ])
                ->where('clients.status', true);

            $query = $this->addCompanyFilterDirect($query, 'clients');

            $this->applyOwnFilter($query, 'clients', 'clients', 'user_id', $currentUser);

            if (!empty($typeFilter)) {
                $query->whereIn('clients.client_type', $typeFilter);
            }

            $query->orderBy('clients.created_at', 'desc');

            return $query->get();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Получить доступные типы клиентов для просмотра взаиморасчетов
     *
     * @param \App\Models\User|null $user Пользователь
     * @return array Массив типов клиентов, к которым есть доступ
     */
    protected function getAllowedMutualSettlementsClientTypes($user = null)
    {
        if (!$user) {
            return [];
        }

        if ($user->is_admin) {
            return ['individual', 'company', 'employee', 'investor'];
        }

        $permissions = $this->getUserPermissionsForCompany($user);
        $hasViewAll = in_array('mutual_settlements_view_all', $permissions);

        if (!$hasViewAll) {
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
     * @param string $search_request Поисковый запрос (может содержать несколько слов)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    function searchClient(string $search_request)
    {
        /** @var User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('clients_search_' . md5($search_request), [$currentUser?->id, $companyId]);
        return CacheService::rememberSearch($cacheKey, function () use ($search_request, $currentUser) {
            $searchTerms = explode(' ', $search_request);

            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'user:id,name,photo',
                'employee:id,name,surname,position,photo'
            ])
                ->select([
                    'clients.*',
                    'clients.balance as balance'
                ])
                ->where('clients.status', true);

            $query = $this->addCompanyFilterDirect($query, 'clients');

            $this->applyOwnFilter($query, 'clients', 'clients', 'user_id', $currentUser);

            $query->where(function ($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $q->orWhere(function ($subQuery) use ($term) {
                        $subQuery->where('clients.first_name', 'like', "%{$term}%")
                            ->orWhere('clients.last_name', 'like', "%{$term}%")
                            ->orWhere('clients.contact_person', 'like', "%{$term}%")
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
     * @param int $id ID клиента
     * @return Client|null
     */
    public function getItemById($id)
    {
        $cacheKey = $this->generateCacheKey('client', [$id]);

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'user:id,name,photo',
                'employee:id,name,surname,position,photo'
            ])
                ->select([
                    'clients.*',
                    'clients.balance as balance'
                ])
                ->where('clients.id', $id);

            $query = $this->addCompanyFilterDirect($query, 'clients');

            return $query->first();
        });
    }

    /**
     * Создать клиента
     *
     * @param array $data Данные клиента
     * @return Client
     */
    public function createItem(array $data)
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
                'last_name'      => $data['last_name'] ?? null,
                'patronymic'     => $data['patronymic'] ?? null,
                'contact_person' => $data['contact_person'] ?? null,
                'position'       => $data['position'] ?? null,
                'client_type'    => $data['client_type'],
                'address'        => $data['address'] ?? null,
                'note'           => $data['note'] ?? null,
                'status'         => $data['status'] ?? true,
                'discount' => $data['discount'] ?? 0,
                'discount_type'  => $data['discount_type'] ?? null,
            ]);

            $this->syncPhones($client->id, $data['phones'] ?? []);
            $this->syncEmails($client->id, $data['emails'] ?? []);

            return $client;
        });

        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($client->id);

        return $client->load('phones', 'emails', 'user', 'employee');
    }

    /**
     * Обновить клиента
     *
     * @param int $id ID клиента
     * @param array $data Данные для обновления
     * @return Client
     */
    public function updateItem($id, array $data)
    {
        $client = DB::transaction(function () use ($id, $data) {
            $client = Client::findOrFail($id);
            $updateData = [
                'user_id'        => $data['user_id'] ?? $client->user_id,
                'employee_id'    => $data['employee_id'] ?? $client->employee_id,
                'first_name'     => $data['first_name'],
                'is_conflict'    => $data['is_conflict'] ?? false,
                'is_supplier'    => $data['is_supplier'] ?? false,
                'client_type'    => $data['client_type'],
                'status'         => $data['status'] ?? true,
                'discount' => $data['discount'] ?? 0,
            ];

            $nullableFields = ['last_name', 'patronymic', 'contact_person', 'position', 'address', 'note', 'discount_type'];
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

        return $client->load('phones', 'emails', 'user', 'employee');
    }

    /**
     * Получить клиентов по массиву ID
     *
     * @param array $ids Массив ID клиентов
     * @return \Illuminate\Support\Collection
     */
    function getItemsByIds(array $ids)
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
                'balance' => $client->balance,
                'is_supplier' => $client->is_supplier,
                'is_conflict' => $client->is_conflict,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'patronymic' => $client->patronymic,
                'contact_person' => $client->contact_person,
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
     * @param int $clientId ID клиента
     * @param bool|null $excludeDebt Исключить кредитные транзакции (true - только платежи, false/null - все)
     * @return array Массив транзакций с описаниями
     */
    public function getBalanceHistory($clientId, $excludeDebt = null)
    {
        $cacheKey = $this->generateCacheKey('client_balance_history', [$clientId, $excludeDebt]);

        return CacheService::remember($cacheKey, function () use ($clientId, $excludeDebt) {
            try {
                $defaultCurrency = Currency::where('is_default', true)->first();
                $defaultCurrencySymbol = $defaultCurrency?->symbol;

                $transactionsQuery = Transaction::where('client_id', $clientId)
                    ->where('is_deleted', false);

                if ($excludeDebt === true) {
                    $transactionsQuery->where('is_debt', false);
                }

                $transactionsQuery->with([
                        'cashRegister:id,name,currency_id',
                        'cashRegister.currency:id,symbol',
                        'currency:id,symbol',
                        'user:id,name',
                        'category:id,name'
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
                        'user_id',
                        'currency_id',
                        'cash_id',
                        'category_id'
                    );

                $transactionsRepository = app(\App\Repositories\TransactionsRepository::class);
                $transactionsQuery = $transactionsRepository->applySourceTypeFilter($transactionsQuery);

                $transactions = $transactionsQuery->get()
                    ->flatMap(function ($item) use ($defaultCurrencySymbol) {
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

                        if ($source === 'receipt') {
                            $receiptId = $item->source_id;

                            if ($item->is_debt) {
                                $description = '📦 Оприходование #' . $receiptId . ' (в кредит)';
                                $amount = +$amount;
                            } else {
                                $description = '💰 Оплата поставщику #' . $receiptId;
                                $amount = -$amount;
                            }

                            $results[] = [
                                'source' => $source,
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
                                'user_name' => $item->user->name,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null
                            ];
                        } elseif ($source === 'transaction') {
                            $transactionId = $item->id;
                            $amount = $item->type == 1 ? +$amount : -$amount;

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
                                'source' => $source,
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
                                'user_name' => $item->user->name,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null
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
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => '🛒 Продажа #' . $saleId . ($item->is_debt ? ' (в кредит)' : ''),
                                'user_id' => $item->user_id,
                                'user_name' => $item->user->name,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null
                            ];
                        } elseif ($source === 'order') {
                            $orderId = $item->source_id;
                            $amount = $item->type == 1 ? +$amount : -$amount;

                            $description = $item->type == 1
                                ? '📋 Заказ #' . $orderId
                                : '💰 Оплата заказа #' . $orderId;

                            $results[] = [
                                'source' => $source,
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
                                'user_name' => $item->user->name,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null
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
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'user_id' => $item->user_id,
                                'user_name' => $item->user->name,
                                'currency_symbol' => $item->cashRegister->currency->symbol ?? $defaultCurrencySymbol,
                                'cash_name' => $item->cashRegister->name ?? null,
                                'category_name' => $item->category->name ?? null
                            ];
                        }

                        return $results;
                    });

                $orders = collect([]);

                $result = $transactions
                    ->concat($orders)
                    ->sortBy('date')
                    ->values()
                    ->all();

                return $result;
            } catch (\Exception $e) {
                Log::error('Error in getBalanceHistory: ' . $e->getMessage(), [
                    'client_id' => $clientId,
                    'exclude_debt' => $excludeDebt,
                    'exception' => $e
                ]);
                throw $e;
            }
        }, $this->getCacheTTL('reference', true));
    }

    /**
     * @param mixed $value
     * @return array
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
     * @param int $id ID клиента
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
     * @param int $clientId ID клиента
     * @param array $phones Массив телефонов
     * @return void
     */
    private function syncPhones(int $clientId, array $phones)
    {
        $existingPhones = ClientsPhone::where('client_id', $clientId)
            ->pluck('phone')
            ->toArray();

        $phonesToAdd = array_diff($phones, $existingPhones);
        $phonesToRemove = array_diff($existingPhones, $phones);

        if (!empty($phonesToAdd)) {
            foreach ($phonesToAdd as $phone) {
                ClientsPhone::create([
                    'client_id' => $clientId,
                    'phone' => $phone,
                ]);
            }
        }

        if (!empty($phonesToRemove)) {
            ClientsPhone::where('client_id', $clientId)
                ->whereIn('phone', $phonesToRemove)
                ->delete();
        }
    }

    /**
     * Синхронизировать email клиента
     *
     * @param int $clientId ID клиента
     * @param array $emails Массив email
     * @return void
     */
    private function syncEmails(int $clientId, array $emails)
    {
        $existingEmails = ClientsEmail::where('client_id', $clientId)
            ->pluck('email')
            ->toArray();

        $emailsToAdd = array_diff($emails, $existingEmails);
        $emailsToRemove = array_diff($existingEmails, $emails);

        if (!empty($emailsToAdd)) {
            foreach ($emailsToAdd as $email) {
                ClientsEmail::create([
                    'client_id' => $clientId,
                    'email' => $email,
                ]);
            }
        }

        if (!empty($emailsToRemove)) {
            ClientsEmail::where('client_id', $clientId)
                ->whereIn('email', $emailsToRemove)
                ->delete();
        }
    }

    /**
     * Инвалидировать кэш баланса клиента
     *
     * @param int $clientId ID клиента
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
}
