<?php

namespace App\Repositories;

use App\Models\Client;
use App\Services\CacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ClientsRepository extends BaseRepository
{


    public function getItemsWithPagination($perPage = 10, $search = null, $includeInactive = false, $page = 1, $statusFilter = null, $typeFilter = null)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('clients_paginated', [$perPage, $search, $includeInactive, $statusFilter, $typeFilter, $currentUser?->id, $companyId]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $includeInactive, $page, $statusFilter, $typeFilter, $currentUser) {
            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'user:id,name,photo',
                'employee:id,name,photo'
            ])
                ->select([
                    'clients.*',
                    'clients.balance as balance'
                ]);

            $companyId = $this->getCurrentCompanyId();

            $query = $this->addCompanyFilterDirect($query, 'clients');

            $this->applyOwnFilter($query, 'clients', 'clients', 'user_id', $currentUser);

            if (!$includeInactive) {
                $query->where('clients.status', true);
            }

            if ($statusFilter !== null && $statusFilter !== '') {
                if ($statusFilter === 'active') {
                    $query->where('clients.status', true);
                } elseif ($statusFilter === 'inactive') {
                    $query->where('clients.status', false);
                }
            }

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

    public function getAllItems()
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('clients_all', [$currentUser?->id, $companyId]);

        return CacheService::remember($cacheKey, function () use ($currentUser) {
            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'user:id,name,photo',
                'employee:id,name,photo'
            ])
                ->select([
                    'clients.*',
                    'clients.balance as balance'
                ])
                ->where('clients.status', true);

            $query = $this->addCompanyFilterDirect($query, 'clients');

            $this->applyOwnFilter($query, 'clients', 'clients', 'user_id', $currentUser);

            $query->orderBy('clients.created_at', 'desc');

            return $query->get();
        }, 1800);
    }

    function searchClient(string $search_request)
    {
        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('clients_search_' . md5($search_request), [$currentUser?->id, $companyId]);
        return CacheService::rememberSearch($cacheKey, function () use ($search_request, $currentUser) {
            $searchTerms = explode(' ', $search_request);

            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'user:id,name,photo',
                'employee:id,name,photo'
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

    public function getItemById($id)
    {
        $cacheKey = $this->generateCacheKey('client', [$id]);

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            $query = Client::with([
                'phones:id,client_id,phone',
                'emails:id,client_id,email',
                'user:id,name,photo',
                'employee:id,name,photo'
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

            return $client;
        });

        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($client->id);

        return $client->load('phones', 'emails', 'user', 'employee');
    }

    public function updateItem($id, array $data)
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
        $cacheKey = $this->generateCacheKey('client_balance_history', [$clientId]);

        return CacheService::remember($cacheKey, function () use ($clientId) {
            try {
                $defaultCurrency = \App\Models\Currency::where('is_default', true)->first();
                $defaultCurrencySymbol = $defaultCurrency ? $defaultCurrency->symbol : '';

                $transactions = DB::table('transactions')
                    ->leftJoin('cash_registers', 'transactions.cash_id', '=', 'cash_registers.id')
                    ->leftJoin('currencies', 'transactions.currency_id', '=', 'currencies.id')
                    ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
                    ->where('transactions.client_id', $clientId)
                    ->where('transactions.is_deleted', false)
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
                            WHEN transactions.source_type = 'App\\\\Models\\\\WhReceipt' THEN 'receipt'
                            ELSE 'transaction'
                        END as source"),
                        'currencies.symbol as currency_symbol',
                        'currencies.code as currency_code',
                        'cash_registers.name as cash_name'
                    )
                    ->get()
                    ->flatMap(function ($item) use ($defaultCurrencySymbol) {
                        $amount = $item->amount;
                        $results = [];

                        if ($item->source === 'receipt') {
                            $receiptId = $item->source_id;

                            if ($item->is_debt) {
                                $description = '📦 Оприходование #' . $receiptId . ' (в кредит)';
                                $amount = +$amount;
                            } else {
                                $description = '💰 Оплата поставщику #' . $receiptId;
                                $amount = -$amount;
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
                                'currency_symbol' => $item->currency_symbol ?? $defaultCurrencySymbol,
                                'currency_code' => $item->currency_code,
                                'cash_name' => $item->cash_name
                            ];
                        } elseif ($item->source === 'transaction') {
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
                                'currency_symbol' => $item->currency_symbol ?? $defaultCurrencySymbol,
                                'currency_code' => $item->currency_code,
                                'cash_name' => $item->cash_name
                            ];
                        } elseif ($item->source === 'sale') {
                            $saleId = $item->source_id;
                            $results[] = [
                                'source' => $item->source,
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
                                'user_name' => $item->user_name,
                                'currency_symbol' => $item->currency_symbol ?? $defaultCurrencySymbol,
                                'currency_code' => $item->currency_code,
                                'cash_name' => $item->cash_name
                            ];
                        } elseif ($item->source === 'order') {
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
                                'amount' => $amount,
                                'orig_amount' => $item->orig_amount,
                                'is_debt' => $item->is_debt,
                                'note' => $item->note,
                                'description' => $description,
                                'user_id' => $item->user_id,
                                'user_name' => $item->user_name,
                                'currency_symbol' => $item->currency_symbol ?? $defaultCurrencySymbol,
                                'currency_code' => $item->currency_code,
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
                                'currency_symbol' => $item->currency_symbol ?? $defaultCurrencySymbol,
                                'currency_code' => $item->currency_code,
                                'cash_name' => $item->cash_name
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
                return [];
            }
        }, 900);
    }


    public function deleteItem($id)
    {
        $result = DB::transaction(function () use ($id) {
            DB::table('clients_emails')->where('client_id', $id)->delete();
            DB::table('clients_phones')->where('client_id', $id)->delete();
            return DB::table('clients')->where('id', $id)->delete();
        });

        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($id);

        return $result;
    }

    public function invalidateClientBalanceCache($clientId)
    {
        CacheService::invalidateClientBalanceCache($clientId);

        CacheService::invalidateClientsCache();
    }

}
