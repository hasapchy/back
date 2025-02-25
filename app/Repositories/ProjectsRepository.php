<?php

namespace App\Repositories;

use App\Models\Project;

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
        $item->save();

        return true;
    }

    // Обновление
    public function updateItem($id, $data)
    {
        $item = Project::find($id);
        $item->name = $data['name'];
        $item->budget = $data['budget'];
        $item->date = $data['date'];
        $item->user_id = $data['user_id'];
        $item->client_id = $data['client_id'];
        $item->users = array_map('strval', $data['users']);
        $item->save();

        return true;
    }

    // // Удаление
    // public function deleteItem($id)
    // {
    //     $item = CashRegister::find($id);
    //     $item->delete();

    //     return true;
    // }
}
