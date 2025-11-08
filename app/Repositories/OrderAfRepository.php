<?php

namespace App\Repositories;

use App\Models\OrderAf;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class OrderAfRepository extends BaseRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null)
    {
        $cacheKey = $this->generateCacheKey('order_af_paginated', [$userUuid, $perPage, md5((string)$search)]);

        return CacheService::getPaginatedData($cacheKey, function() use ($userUuid, $perPage, $search) {
            $query = OrderAf::with(['user:id,name'])
                ->where('user_id', $userUuid);

            if ($search) {
                $query->where('name', 'like', "%{$search}%");
            }

            return $query->orderBy('name')->paginate($perPage);
        }, 1);
    }


    public function getItemById($id, $userUuid = null)
    {
        $cacheKey = $this->generateCacheKey('order_af_item', [$id, $userUuid]);

        return CacheService::getReferenceData($cacheKey, function() use ($id, $userUuid) {
            $query = OrderAf::with(['user:id,name']);

            if ($userUuid) {
                $query->where('user_id', $userUuid);
            }

            return $query->find($id);
        });
    }

    public function createItem($data)
    {
        return DB::transaction(function () use ($data) {
            $field = OrderAf::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'options' => $data['options'] ?? null,
                'required' => $data['required'] ?? false,
                'default' => $data['default'] ?? null,
                'user_id' => $data['user_id'],
            ]);

            CacheService::invalidateByLike('%order_af%');

            return $field;
        });
    }

    public function updateItem($id, $data, $userUuid = null)
    {
        return DB::transaction(function () use ($id, $data, $userUuid) {
            $query = OrderAf::where('id', $id);

            if ($userUuid) {
                $query->where('user_id', $userUuid);
            }

            $field = $query->firstOrFail();

            $field->update([
                'name' => $data['name'],
                'type' => $data['type'],
                'options' => $data['options'] ?? null,
                'required' => $data['required'] ?? false,
                'default' => $data['default'] ?? null,
            ]);

            CacheService::invalidateByLike('%order_af%');

            return $field;
        });
    }

    public function deleteItem($id, $userUuid = null)
    {
        return DB::transaction(function () use ($id, $userUuid) {
            $query = OrderAf::where('id', $id);

            if ($userUuid) {
                $query->where('user_id', $userUuid);
            }

            $field = $query->firstOrFail();

            $field->values()->delete();
            $field->delete();

            CacheService::invalidateByLike('%order_af%');

            return $field;
        });
    }

    public function getFieldTypes()
    {
        return [
            'string' => 'Текст',
            'int' => 'Число',
            'date' => 'Дата',
            'boolean' => 'Да/Нет',
            'select' => 'Выбор из списка',
            'datetime' => 'Дата и время'
        ];
    }
}
