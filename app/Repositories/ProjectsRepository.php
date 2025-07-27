<?php

namespace App\Repositories;

use App\Models\Project;
use Illuminate\Support\Facades\DB;

class ProjectsRepository
{
    // Получение с пагинацией
    public function getItemsWithPagination($userUuid, $perPage = 20)
    {
        $items = Project::leftJoin('users as users', 'projects.user_id', '=', 'users.id')
            ->select('projects.*', 'users.name as user_name')
            ->whereJsonContains('projects.users', (string) $userUuid)
            ->paginate($perPage);
        $client_ids = $items->pluck('client_id')->toArray();

        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        foreach ($items as $item) {
            $item->client = $clients->firstWhere('id', $item->client_id);
        }
        return $items;
    }

    // Получение всего списка
    public function getAllItems($userUuid)
    {
        $items = Project::leftJoin('users as users', 'projects.user_id', '=', 'users.id')
            ->select('projects.*', 'users.name as user_name')
            ->whereJsonContains('projects.users', (string) $userUuid)
            ->get();
        $client_ids = $items->pluck('client_id')->toArray();

        $client_repository = new ClientsRepository();
        $clients = $client_repository->getItemsByIds($client_ids);

        foreach ($items as $item) {
            $item->client = $clients->firstWhere('id', $item->client_id);
        }
        return $items;
    }

    // Создание
    public function createItem($data)
    {
        $item = new Project();
        $item->name = $data['name'];
        $item->budget = $data['budget'];
        $item->date = $data['date'];
        $item->user_id = $data['user_id'];
        $item->client_id = $data['client_id'];
        $item->users = array_map('strval', $data['users']);
        $item->files = $data['files'] ?? [];
        $item->save();

        return true;
    }

    // Обновление
    public function updateItem($id, $data)
    {
        $item = Project::find($id);

        // Защита: если files переданы, убедись, что это массив с нужной структурой
        if (isset($data['files']) && is_array($data['files'])) {
            $item->files = $data['files'];
        }

        $item->name = $data['name'];
        $item->budget = $data['budget'];
        $item->date = $data['date'];
        $item->user_id = $data['user_id'];
        $item->client_id = $data['client_id'];
        $item->users = array_map('strval', $data['users']);

        $item->save();

        return true;
    }

    public function findItem($id)
    {
        return Project::find($id);
    }

    // Удаление
    public function deleteItem($id)
    {
        $item = Project::find($id);
        if (!$item) {
            return false;
        }
        $item->delete();
        return true;
    }

    // Получение истории баланса проекта
    public function getBalanceHistory($projectId)
    {
        $result = [];

        // Продажи по проекту
        $sales = DB::table('sales')
            ->where('project_id', $projectId)
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

        // Оприходования по проекту
        $receipts = DB::table('wh_receipts')
            ->where('project_id', $projectId)
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

        // Транзакции по проекту
        $transactions = DB::table('transactions')
            ->where('project_id', $projectId)
            ->select('id', 'created_at', 'orig_amount', 'type')
            ->get()
            ->map(function ($tr) {
                $isIncome = $tr->type === 1;
                return [
                    'source' => 'transaction',
                    'source_id' => $tr->id,
                    'date' => $tr->created_at,
                    'amount' => $isIncome ? -$tr->orig_amount : +$tr->orig_amount,
                    'description' => $isIncome ? 'Проект оплатил нам' : 'Мы оплатили по проекту'
                ];
            });

        // Заказы по проекту
        $orders = DB::table('orders')
            ->where('project_id', $projectId)
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

        // Объединяем и сортируем
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

    // Получение текущего баланса проекта
    public function getBalance($projectId)
    {
        $history = $this->getBalanceHistory($projectId);
        return collect($history)->sum('amount');
    }
}
