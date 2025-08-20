<?php

namespace App\Repositories;

use App\Models\OrderAf;
use App\Models\OrderCategory;
use Illuminate\Support\Facades\DB;

class OrderAfRepository
{
    public function getItemsWithPagination($userUuid, $perPage = 20, $search = null)
    {
        $query = OrderAf::with(['categories:id,name', 'user:id,name'])
            ->where('user_id', $userUuid);

        if ($search) {
            $query->where('name', 'like', "%{$search}%");
        }

        return $query->orderBy('name')->paginate($perPage);
    }


    public function getItemById($id, $userUuid = null)
    {
        $query = OrderAf::with(['categories:id,name', 'user:id,name']);

        if ($userUuid) {
            $query->where('user_id', $userUuid);
        }

        return $query->find($id);
    }

    public function getFieldsByCategory($categoryId, $userUuid = null)
    {
        $query = OrderAf::whereHas('categories', function ($query) use ($categoryId) {
            $query->where('order_category_id', $categoryId);
        });

        if ($userUuid) {
            $query->where('user_id', $userUuid);
        }

        return $query->orderBy('name')->get();
    }

    public function getFieldsByCategories(array $categoryIds, $userUuid = null)
    {
        $query = OrderAf::whereHas('categories', function ($query) use ($categoryIds) {
            $query->whereIn('order_category_id', $categoryIds);
        });

        if ($userUuid) {
            $query->where('user_id', $userUuid);
        }

        return $query->orderBy('name')->get();
    }

    public function createItem($data)
    {
        try {
            DB::beginTransaction();

            $field = OrderAf::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'options' => $data['options'] ?? null,
                'required' => $data['required'] ?? false,
                'default' => $data['default'] ?? null,
                'user_id' => $data['user_id'],
            ]);

            if (!empty($data['category_ids'])) {
                $field->categories()->attach($data['category_ids']);
            }

            DB::commit();
            return $field;
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    public function updateItem($id, $data, $userUuid = null)
    {
        try {
            DB::beginTransaction();

            $field = OrderAf::where('id', $id);

            if ($userUuid) {
                $field->where('user_id', $userUuid);
            }

            $field = $field->first();

            if (!$field) {
                throw new \Exception('Поле не найдено');
            }


            $field->update([
                'name' => $data['name'],
                'type' => $data['type'],
                'options' => $data['options'] ?? null,
                'required' => $data['required'] ?? false,
                'default' => $data['default'] ?? null,
            ]);

            if (isset($data['category_ids'])) {
                $field->categories()->sync($data['category_ids']);
            }

            DB::commit();
            return $field;
        } catch (\Throwable $th) {
            DB::rollBack();
            throw $th;
        }
    }

    public function deleteItem($id, $userUuid = null)
    {
        $field = OrderAf::where('id', $id);

        if ($userUuid) {
            $field->where('user_id', $userUuid);
        }

        $field = $field->first();

        if (!$field) {
            throw new \Exception('Поле не найдено');
        }

        $field->categories()->detach();

        $field->values()->delete();

        $field->delete();

        return $field;
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
