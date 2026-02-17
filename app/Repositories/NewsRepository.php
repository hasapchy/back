<?php

namespace App\Repositories;

use App\Models\News;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;

class NewsRepository extends BaseRepository
{
    /**
     * Получить базовые связи для новостей
     */
    private function getBaseRelations(): array
    {
        return [
            'user:id,name,email',
            'author:id,name,email', // Алиас для обратной совместимости
            'company:id,name',
        ];
    }

    /**
     * Получить новости с пагинацией
     */
    public function getItemsWithPagination($perPage = 20, $page = 1, $search = null, $dateFrom = null, $dateTo = null, $authorId = null)
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('news_paginated', [$perPage, $search, $dateFrom, $dateTo, $authorId, $currentUser?->id, $companyId]);

        $ttl = ! $search ? 1800 : 600;

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $search, $page, $dateFrom, $dateTo, $authorId) {
            $query = News::select(['news.*'])
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterDirect($query, 'news');

            if ($search) {
                $searchTrimmed = trim((string) $search);
                $searchLower = mb_strtolower($searchTrimmed);
                $query->where(function ($q) use ($searchTrimmed, $searchLower) {
                    $q->where('news.id', 'like', "%{$searchTrimmed}%")
                        ->orWhereRaw('LOWER(news.title) LIKE ?', ["%{$searchLower}%"]);
                });
            }

            if ($dateFrom) {
                $query->whereDate('news.created_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->whereDate('news.created_at', '<=', $dateTo);
            }

            if (! empty($authorId)) {
                $query->where('news.creator_id', $authorId);
            }

            return $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int) $page);
        }, (int) $page);
    }

    /**
     * Получить все новости
     */
    public function getAllItems($authorId = null)
    {
        $currentUser = auth('api')->user();
        $companyId = $this->getCurrentCompanyId();
        $cacheKey = $this->generateCacheKey('news_all', [$currentUser?->id, $companyId, $authorId]);

        return CacheService::getReferenceData($cacheKey, function () use ($authorId) {
            $query = News::select(['news.*'])
                ->with($this->getBaseRelations());

            $query = $this->addCompanyFilterDirect($query, 'news');

            if ($authorId !== null) {
                $query->where('news.creator_id', $authorId);
            }

            return $query->orderBy('created_at', 'desc')->get();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Создать новость
     */
    public function createItem(array $data)
    {
        return DB::transaction(function () use ($data) {
            $companyId = $this->getCurrentCompanyId();

            $item = new News;
            $item->title = $data['title'];
            $item->content = $data['content'];
            $item->creator_id = $data['creator_id'] ?? $data['author_id'] ?? null; // Поддержка старого ключа для обратной совместимости
            $item->company_id = $companyId;
            $item->save();

            CacheService::invalidateByLike('%news%');

            return $item->load($this->getBaseRelations());
        });
    }

    /**
     * Обновить новость
     */
    public function updateItem(int $id, array $data): bool
    {
        return DB::transaction(function () use ($id, $data) {
            $item = News::findOrFail($id);

            $item->title = $data['title'];
            $item->content = $data['content'];
            $item->save();

            CacheService::invalidateByLike('%news%');

            return true;
        });
    }

    /**
     * Найти новость с отношениями
     */
    public function findItemWithRelations($id)
    {
        $cacheKey = $this->generateCacheKey('news_item_relations', [$id]);

        return CacheService::remember($cacheKey, function () use ($id) {
            return News::select([
                'news.id',
                'news.title',
                'news.content',
                'news.creator_id',
                'news.company_id',
                'news.created_at',
                'news.updated_at',
            ])
                ->with($this->getBaseRelations())
                ->where('id', $id)
                ->first();
        }, $this->getCacheTTL('reference'));
    }

    /**
     * Удалить новость
     */
    public function deleteItem($id)
    {
        return DB::transaction(function () use ($id) {
            $item = News::findOrFail($id);
            $item->delete();

            CacheService::invalidateByLike('%news%');

            return true;
        });
    }
}
