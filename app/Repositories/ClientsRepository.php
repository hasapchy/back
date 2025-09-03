<?php

namespace App\Repositories;

use App\Models\Client;
use App\Models\ClientBalance;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientsRepository
{


    function getItemsPaginated($perPage = 20, $search = null)
    {
        // Создаем уникальный ключ кэша
        $cacheKey = "clients_paginated_{$perPage}_{$search}";

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search) {
            // Используем подзапросы для получения телефонов и email'ов как JSON
            $query = Client::select([
                'clients.*',
                DB::raw('COALESCE(client_balances.balance, 0) as balance_amount'),
                DB::raw('(SELECT JSON_ARRAYAGG(JSON_OBJECT("id", cp.id, "client_id", cp.client_id, "phone", cp.phone)) FROM clients_phones cp WHERE cp.client_id = clients.id) as phones_json'),
                DB::raw('(SELECT JSON_ARRAYAGG(JSON_OBJECT("id", ce.id, "client_id", ce.client_id, "email", ce.email)) FROM clients_emails ce WHERE ce.client_id = clients.id) as emails_json')
            ])
            ->leftJoin('client_balances', 'clients.id', '=', 'client_balances.client_id');

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('clients.id', 'like', "%{$search}%")
                        ->orWhere('clients.first_name', 'like', "%{$search}%")
                        ->orWhere('clients.last_name', 'like', "%{$search}%")
                        ->orWhere('clients.contact_person', 'like', "%{$search}%")
                        ->orWhere('clients.address', 'like', "%{$search}%")
                        ->orWhereExists(function ($subQuery) use ($search) {
                            $subQuery->select(DB::raw(1))
                                ->from('clients_phones')
                                ->whereColumn('clients_phones.client_id', 'clients.id')
                                ->where('clients_phones.phone', 'like', "%{$search}%");
                        })
                        ->orWhereExists(function ($subQuery) use ($search) {
                            $subQuery->select(DB::raw(1))
                                ->from('clients_emails')
                                ->whereColumn('clients_emails.client_id', 'clients.id')
                                ->where('clients_emails.email', 'like', "%{$search}%");
                        });
                });
            }

            $results = $query->orderBy('clients.created_at', 'desc')->paginate($perPage);

            // Логируем результаты пагинации для отладки
            Log::info("=== CLIENT PAGINATION BACKEND ===");
            Log::info("Page: {$perPage}");
            Log::info("Search: " . ($search ?? 'NULL'));
            Log::info("Results count: " . $results->count());
            if ($results->count() > 0) {
                $firstResult = $results->first();
                Log::info("First result ID: " . $firstResult->id);
                Log::info("First result balance_amount: " . ($firstResult->balance_amount ?? 'NULL'));
                Log::info("First result balance_amount type: " . gettype($firstResult->balance_amount ?? null));
            }
            Log::info("================================");

            return $results;
        });
    }

    function searchClient(string $search_request)
    {
        $cacheKey = "clients_search_{$search_request}";

        return CacheService::getReferenceData($cacheKey, function () use ($search_request) {
            $searchTerms = explode(' ', $search_request);

            $query = Client::select([
                'clients.*',
                DB::raw('COALESCE(client_balances.balance, 0) as balance_amount'),
                DB::raw('(SELECT JSON_ARRAYAGG(JSON_OBJECT("id", cp.id, "client_id", cp.client_id, "phone", cp.phone)) FROM clients_phones cp WHERE cp.client_id = clients.id) as phones_json'),
                DB::raw('(SELECT JSON_ARRAYAGG(JSON_OBJECT("id", ce.id, "client_id", ce.client_id, "email", ce.email)) FROM clients_emails ce WHERE ce.client_id = clients.id) as emails_json')
            ])
            ->leftJoin('client_balances', 'clients.id', '=', 'client_balances.client_id');

            foreach ($searchTerms as $term) {
                $query->orWhere(function ($q) use ($term) {
                    $q->where('clients.first_name', 'like', "%{$term}%")
                        ->orWhere('clients.last_name', 'like', "%{$term}%")
                        ->orWhere('clients.contact_person', 'like', "%{$term}%")
                        ->orWhereExists(function ($subQuery) use ($term) {
                            $subQuery->select(DB::raw(1))
                                ->from('clients_phones')
                                ->whereColumn('clients_phones.client_id', 'clients.id')
                                ->where('clients_phones.phone', 'like', "%{$term}%");
                        })
                        ->orWhereExists(function ($subQuery) use ($term) {
                            $subQuery->select(DB::raw(1))
                                ->from('clients_emails')
                                ->whereColumn('clients_emails.client_id', 'clients.id')
                                ->where('clients_emails.email', 'like', "%{$term}%");
                        });
                });
            }

            $results = $query->limit(50)->get();

            // Логируем результаты поиска для отладки
            Log::info("=== CLIENT SEARCH BACKEND ===");
            Log::info("Search request: {$search_request}");
            Log::info("Results count: " . $results->count());
            if ($results->count() > 0) {
                $firstResult = $results->first();
                Log::info("First result ID: " . $firstResult->id);
                Log::info("First result balance_amount: " . ($firstResult->balance_amount ?? 'NULL'));
                Log::info("First result balance_amount type: " . gettype($firstResult->balance_amount ?? null));
            }
            Log::info("=============================");

            return $results;
        });
    }

    public function getItem($id)
    {
        $cacheKey = "client_{$id}";

        return CacheService::getReferenceData($cacheKey, function () use ($id) {
            $result = Client::select([
                'clients.*',
                DB::raw('COALESCE(client_balances.balance, 0) as balance_amount'),
                DB::raw('(SELECT JSON_ARRAYAGG(JSON_OBJECT("id", cp.id, "client_id", cp.client_id, "phone", cp.phone)) FROM clients_phones cp WHERE cp.client_id = clients.id) as phones_json'),
                DB::raw('(SELECT JSON_ARRAYAGG(JSON_OBJECT("id", ce.id, "client_id", ce.client_id, "email", ce.email)) FROM clients_emails ce WHERE ce.client_id = clients.id) as emails_json')
            ])
            ->leftJoin('client_balances', 'clients.id', '=', 'client_balances.client_id')
            ->where('clients.id', $id)
            ->first();

            // Логируем результат для отладки
            Log::info("=== CLIENT GET ITEM BACKEND ===");
            Log::info("Client ID: {$id}");
            Log::info("Full result: " . json_encode($result));
            Log::info("Balance amount: " . ($result->balance_amount ?? 'NULL'));
            Log::info("Balance amount type: " . gettype($result->balance_amount ?? null));
            Log::info("Phones JSON: " . ($result->phones_json ?? 'NULL'));
            Log::info("Emails JSON: " . ($result->emails_json ?? 'NULL'));
            Log::info("=================================");

            return $result;
        });
    }

    public function create(array $data)
    {
        $client = DB::transaction(function () use ($data) {
            $client = Client::create([
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

        // Инвалидируем кэш клиентов и баланса
        CacheService::invalidateClientsCache();
        $this->invalidateClientBalanceCache($client->id);

        return $client;
    }

    public function update($id, array $data)
    {
        $client = DB::transaction(function () use ($id, $data) {
            $client = Client::findOrFail($id);
            $client->update([
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
            ->leftJoin('client_balances', 'clients.id', '=', 'client_balances.client_id')
            ->select(
                'clients.id as id',
                'clients.client_type as client_type',
                DB::raw('COALESCE(client_balances.balance, 0) as balance'),
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
            $result = [];

            // Продажи
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

            // Оприходования
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
                // Если таблица не существует, используем пустой массив
                $receipts = collect();
            }

            // Транзакции
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

            // Заказы
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
