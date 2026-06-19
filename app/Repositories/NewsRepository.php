<?php

namespace App\Repositories;

use App\Models\News;
use App\Models\NewsAcknowledgement;
use App\Models\NewsView;
use App\Models\NewsReaction;
use App\Services\CacheService;
use App\Services\ReactionToggleService;
use App\Services\Timeline\TimelineUserFormatter;
use App\Support\Timeline\ViewedByBuilder;
use Illuminate\Support\Facades\DB;

class NewsRepository extends BaseRepository
{
    public function __construct(
        private readonly ReactionToggleService $reactionToggleService
    ) {}
    /**
     * Получить базовые связи для новостей
     */
    private function getBaseRelations(): array
    {
        return [
            'author:id,name,surname,email,photo',
            'company:id,name',
        ];
    }

    /**
     * @param \Illuminate\Database\Eloquent\Builder<News> $query
     * @return \Illuminate\Database\Eloquent\Builder<News>
     */
    private function applyNewsEngagementCounts($query)
    {
        return $query->withCount([
            'reactions',
            'comments as comments_count',
            'acknowledgements as acknowledgements_count',
        ]);
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
            $query = $this->applyNewsEngagementCounts($query);

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
     * @param iterable<int, News> $items
     * @return void
     */
    public function attachReactionSummaries(iterable $items): void
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item instanceof News && $item->id) {
                $ids[] = (int) $item->id;
            }
        }

        if ($ids === []) {
            return;
        }

        $summaries = $this->reactionToggleService->summarizeByForeignIds(
            NewsReaction::class,
            'news_id',
            $ids
        );

        foreach ($items as $item) {
            if (! $item instanceof News) {
                continue;
            }
            $item->setAttribute('reactions_summary', $summaries[(int) $item->id] ?? []);
        }
    }

    /**
     * @param iterable<int, News> $items
     * @param int $companyId
     * @return void
     */
    public function attachViewedBy(iterable $items, int $companyId): void
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item instanceof News && $item->id) {
                $ids[] = (int) $item->id;
            }
        }

        if ($ids === []) {
            return;
        }

        $states = NewsView::query()
            ->select([
                'news_views.news_id',
                'news_views.user_id',
                'news_views.viewed_at',
            ])
            ->with(['user:id,name,surname'])
            ->whereIn('news_id', $ids)
            ->where('company_id', $companyId)
            ->whereNotNull('viewed_at')
            ->get()
            ->groupBy('news_id');

        $acknowledgements = NewsAcknowledgement::query()
            ->select(['news_id', 'user_id', 'acknowledged_at'])
            ->whereIn('news_id', $ids)
            ->where('company_id', $companyId)
            ->whereNotNull('acknowledged_at')
            ->get()
            ->groupBy('news_id');

        foreach ($items as $item) {
            if (! $item instanceof News) {
                continue;
            }

            $newsId = (int) $item->id;
            $ackByUser = collect($acknowledgements->get($newsId, []))
                ->keyBy(fn(NewsAcknowledgement $ack) => (int) $ack->user_id);

            $rows = collect($states->get($newsId, []))
                ->map(function (NewsView $state) use ($ackByUser) {
                    $name = TimelineUserFormatter::fullName($state->user);
                    $viewedAt = $state->viewed_at;
                    $ack = $ackByUser->get((int) $state->user_id);
                    if ($ack?->acknowledged_at && $viewedAt && $viewedAt->gt($ack->acknowledged_at)) {
                        $viewedAt = $ack->acknowledged_at;
                    }

                    return [
                        'user_id' => (int) $state->user_id,
                        'name' => $name,
                        'viewed_at' => optional($viewedAt)->toISOString(),
                    ];
                })
                ->filter(fn(array $row) => $row['name'] !== '' && $row['viewed_at'] !== null)
                ->values()
                ->all();

            $item->setAttribute('viewed_by', ViewedByBuilder::withCreator($rows, $item->creator, $item->created_at));
        }
    }

    /**
     * @param iterable<int, News> $items
     * @param int $userId
     * @param int $companyId
     * @return void
     */
    public function attachAcknowledgedByCurrentUser(iterable $items, int $userId, int $companyId): void
    {
        if ($userId < 1) {
            return;
        }

        $ids = [];
        foreach ($items as $item) {
            if ($item instanceof News && $item->id) {
                $ids[] = (int) $item->id;
            }
        }

        if ($ids === []) {
            return;
        }

        $acknowledgedMap = NewsAcknowledgement::query()
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->whereIn('news_id', $ids)
            ->get()
            ->keyBy('news_id');

        foreach ($items as $item) {
            if (! $item instanceof News) {
                continue;
            }
            $ack = $acknowledgedMap->get((int) $item->id);
            $item->setAttribute('acknowledged_at', $ack?->acknowledged_at?->toISOString());
            $item->setAttribute('acknowledged_by_me', $ack !== null);
        }
    }

    /**
     * @param iterable<int, News> $items
     * @param int $companyId
     * @return void
     */
    public function attachAcknowledgedBy(iterable $items, int $companyId): void
    {
        $ids = [];
        foreach ($items as $item) {
            if ($item instanceof News && $item->id) {
                $ids[] = (int) $item->id;
            }
        }

        if ($ids === []) {
            return;
        }

        $acknowledgements = NewsAcknowledgement::query()
            ->select([
                'news_acknowledgements.news_id',
                'news_acknowledgements.user_id',
                'news_acknowledgements.acknowledged_at',
            ])
            ->with(['user:id,name,surname'])
            ->whereIn('news_id', $ids)
            ->where('company_id', $companyId)
            ->whereNotNull('acknowledged_at')
            ->orderByDesc('acknowledged_at')
            ->get()
            ->groupBy('news_id');

        foreach ($items as $item) {
            if (! $item instanceof News) {
                continue;
            }

            $rows = collect($acknowledgements->get((int) $item->id, []))
                ->map(function (NewsAcknowledgement $ack) {
                    $name = TimelineUserFormatter::fullName($ack->user);
                    return [
                        'user_id' => (int) $ack->user_id,
                        'name' => $name,
                        'viewed_at' => optional($ack->acknowledged_at)->toISOString(),
                    ];
                })
                ->filter(fn(array $row) => $row['name'] !== '' && $row['viewed_at'] !== null)
                ->values()
                ->all();

            $item->setAttribute('acknowledged_by', ViewedByBuilder::sortByViewedAtDesc($rows));
        }
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
            $query = $this->applyNewsEngagementCounts($query);

            $query = $this->addCompanyFilterDirect($query, 'news');

            if ($authorId !== null) {
                $query->where('news.creator_id', $authorId);
            }

            return $query->orderBy('created_at', 'desc')->get();
        });
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
            $item->is_important = (bool) ($data['is_important'] ?? false);
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
            if (array_key_exists('is_important', $data)) {
                $item->is_important = (bool) $data['is_important'];
            }
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
            $query = News::select([
                'news.id',
                'news.title',
                'news.content',
                'news.is_important',
                'news.creator_id',
                'news.company_id',
                'news.created_at',
                'news.updated_at',
            ])
                ->with($this->getBaseRelations());

            return $this->applyNewsEngagementCounts($query)
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

    /**
     * @param int $newsId
     * @param int $userId
     * @param int $companyId
     * @return string
     */
    public function acknowledgeImportant(int $newsId, int $userId, int $companyId): string
    {
        $ackAt = now();

        $ack = NewsAcknowledgement::query()->firstOrCreate(
            [
                'news_id' => $newsId,
                'user_id' => $userId,
                'company_id' => $companyId,
            ],
            [
                'acknowledged_at' => $ackAt,
            ]
        );

        NewsView::query()->firstOrCreate(
            [
                'news_id' => $newsId,
                'user_id' => $userId,
                'company_id' => $companyId,
            ],
            [
                'viewed_at' => $ackAt,
            ]
        );

        CacheService::invalidateByLike('%news%');

        return optional($ack->acknowledged_at ?? $ackAt)->toISOString();
    }

    /**
     * @param int $newsId
     * @param int $userId
     * @param int $companyId
     * @return string
     */
    public function markViewed(int $newsId, int $userId, int $companyId): string
    {
        $view = NewsView::query()->firstOrCreate(
            [
                'news_id' => $newsId,
                'user_id' => $userId,
                'company_id' => $companyId,
            ],
            [
                'viewed_at' => now(),
            ]
        );

        CacheService::invalidateByLike('%news%');

        return optional($view->viewed_at)->toISOString();
    }
}
