<?php

namespace App\Repositories;

use App\Models\MessageTemplate;
use App\Services\CacheService;

class MessageTemplateRepository extends BaseRepository
{
    /**
     * Получить базовые связи для шаблонов сообщений
     *
     * @return array<string>
     */
    private function getBaseRelations(): array
    {
        return [
            'user:id,name,surname,email',
            'company:id,name',
        ];
    }

    /**
     * Получить шаблоны с пагинацией
     *
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  array<string, mixed>  $filters  Фильтры (type, search)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($perPage = 20, $page = 1, $filters = [])
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $filtersKey = ! empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('message_templates_paginated', [
            $perPage, $filtersKey, $currentUser?->id, $companyId,
        ]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $filters) {
            $query = MessageTemplate::select(['message_templates.*'])
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterDirect($query, 'message_templates');

            $this->applyFilters($query, $filters);

            return $query->orderBy('message_templates.created_at', 'desc')
                ->paginate($perPage);
        }, (int) $page);
    }

    /**
     * Получить все шаблоны
     *
     * @param  array<string, mixed>  $filters  Фильтры (type)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllItems($filters = [])
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $filtersKey = ! empty($filters) ? md5(json_encode($filters)) : 'no_filters';
        $cacheKey = $this->generateCacheKey('message_templates_all', [
            $filtersKey, $currentUser?->id, $companyId,
        ]);

        return CacheService::getReferenceData($cacheKey, function () use ($filters) {
            $query = MessageTemplate::select(['message_templates.*'])
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterDirect($query, 'message_templates');

            $this->applyFilters($query, $filters);

            return $query->orderBy('message_templates.created_at', 'desc')->get();
        });
    }

    /**
     * Получить шаблон по типу (например, для ДР)
     *
     * @param  string  $type  Тип шаблона
     * @return \App\Models\MessageTemplate|null
     */
    public function getByType(string $type)
    {
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('message_template_type', [$type, $companyId]);

        return CacheService::remember($cacheKey, function () use ($type) {
            $query = MessageTemplate::where('message_templates.type', $type)
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterDirect($query, 'message_templates');

            return $query->first();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Получить шаблон по ID
     *
     * @param  int  $id  ID шаблона
     * @return \App\Models\MessageTemplate|null
     */
    public function getItemById($id)
    {
        return MessageTemplate::with($this->getBaseRelations())->findOrFail($id);
    }

    /**
     * Создать шаблон
     *
     * @param  array<string, mixed>  $data  Данные шаблона
     * @return \App\Models\MessageTemplate
     */
    public function createItem($data)
    {
        $companyId = $this->getCurrentCompanyId();

        $itemData = array_merge($data, [
            'company_id' => $companyId,
        ]);

        $item = MessageTemplate::create($itemData);
        CacheService::invalidateMessageTemplatesCache();

        return $item->load($this->getBaseRelations());
    }

    /**
     * Обновить шаблон
     *
     * @param  int  $id  ID шаблона
     * @param  array<string, mixed>  $data  Данные для обновления
     * @return \App\Models\MessageTemplate
     */
    public function updateItem($id, $data)
    {
        $item = MessageTemplate::findOrFail($id);
        $item->update($data);
        CacheService::invalidateMessageTemplatesCache();

        return $item->load($this->getBaseRelations());
    }

    /**
     * Найти шаблон с отношениями
     *
     * @param  int  $id  ID шаблона
     * @return \App\Models\MessageTemplate|null
     */
    public function findItemWithRelations($id)
    {
        $cacheKey = $this->generateCacheKey('message_template_item', [$id]);

        return CacheService::remember($cacheKey, function () use ($id) {
            return MessageTemplate::select([
                'message_templates.id',
                'message_templates.type',
                'message_templates.name',
                'message_templates.content',
                'message_templates.company_id',
                'message_templates.creator_id',
                'message_templates.is_active',
                'message_templates.created_at',
                'message_templates.updated_at',
            ])
                ->with($this->getBaseRelations())
                ->where('message_templates.id', $id)
                ->first();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Удалить шаблон
     *
     * @param  int  $id  ID шаблона
     * @return bool
     */
    public function deleteItem($id)
    {
        $item = MessageTemplate::findOrFail($id);
        $item->delete();
        CacheService::invalidateMessageTemplatesCache();

        return true;
    }

    /**
     * Применить фильтры к запросу шаблонов
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Query builder
     * @param  array<string, mixed>  $filters  Массив фильтров:
     *                                         - type (string|null) Тип шаблона
     *                                         - search (string|null) Поиск по названию и содержанию
     * @return void
     */
    private function applyFilters($query, array $filters)
    {
        $query->when(isset($filters['type']), fn ($q) => $q->where('message_templates.type', $filters['type']))
            ->when(isset($filters['search']), function ($q) use ($filters) {
                $search = trim($filters['search']);
                $q->where(function ($query) use ($search) {
                    $query->where('message_templates.name', 'like', "%{$search}%")
                        ->orWhere('message_templates.content', 'like', "%{$search}%");
                });
            });
    }
}
