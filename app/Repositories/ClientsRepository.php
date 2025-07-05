<?php

namespace App\Repositories;

use App\Models\Client;
use Illuminate\Support\Facades\DB;

class ClientsRepository
{


    function getImemsPaginated($perPage = 20)
    {

        $clients = DB::table('clients')
            ->leftJoin('client_balances', 'clients.id', '=', 'client_balances.client_id')
            ->select(
                'clients.id as id',
                'clients.client_type as client_type',
                'client_balances.balance as balance',
                'clients.is_supplier as is_supplier',
                'clients.is_conflict as is_conflict',
                'clients.first_name as first_name',
                'clients.last_name as last_name',
                'clients.contact_person as contact_person',
                'clients.address as address',
                'clients.note as note',
                'clients.status as status',
                'clients.discount as discount',
                'clients.discount_type as discount_type',
                'clients.created_at as created_at',
                'clients.updated_at as updated_at'
            )
            ->paginate($perPage);

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

    function searchClient(string $search_request)
    {
        $searchTerms = explode(' ', $search_request);

        $query = DB::table('clients');

        foreach ($searchTerms as $term) {
            $query->orWhere(function ($q) use ($term) {
                $q->where('first_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('contact_person', 'like', "%{$term}%");
            });
        }

        $clientIds = $query->pluck('id')->toArray();

        $phoneClientIds = DB::table('clients_phones')
            ->where(function ($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $q->orWhere('phone', 'like', "%{$term}%");
                }
            })
            ->pluck('client_id')
            ->toArray();

        $emailClientIds = DB::table('clients_emails')
            ->where(function ($q) use ($searchTerms) {
                foreach ($searchTerms as $term) {
                    $q->orWhere('email', 'like', "%{$term}%");
                }
            })
            ->pluck('client_id')
            ->toArray();

        $allClientIds = array_unique(array_merge($clientIds, $phoneClientIds, $emailClientIds));
        $allClientIds = array_unique($allClientIds);

        $clients = $this->getItemsByIds($allClientIds);
        return $clients;
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

        return $client;
    }

    function getItemsByIds(array $ids)
    {

        $clients = DB::table('clients')
            ->leftJoin('client_balances', 'clients.id', '=', 'client_balances.client_id')
            ->select(
                'clients.id as id',
                'clients.client_type as client_type',
                'client_balances.balance as balance',
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
        $result = [];

        // Продажи
        $sales = DB::table('sales')
            ->where('client_id', $clientId)
            ->select('id', 'created_at', 'total_price', 'cash_id')
            ->get()
            ->map(function ($sale) {
                return [
                    'source' => 'sale',
                    'source_id' => $sale->id,
                    'date' => $sale->created_at,
                    'amount' => $sale->cash_id +$sale->total_price ,
                    'description' => $sale->cash_id ? 'Продажа через кассу' : 'Продажа в баланс(долг)'
                ];
            });

        // Оприходования
        $receipts = DB::table('wh_receipts')
            ->where('supplier_id', $clientId)
            ->select('id', 'created_at', 'amount', 'cash_id')
            ->get()
            ->map(function ($receipt) {
                return [
                    'source' => 'receipt',
                    'source_id' => $receipt->id,
                    'date' => $receipt->created_at,
                    'amount' => $receipt->cash_id -$receipt->amount ,
                    'description' => $receipt->cash_id ? 'Долг за оприходование(в кассу)' : 'Долг за оприходование(в баланс)'
                ];
            });

        // Транзакции
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


        // Заказы
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
    }
}
