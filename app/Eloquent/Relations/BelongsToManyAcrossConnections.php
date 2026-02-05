<?php

namespace App\Eloquent\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * BelongsToMany для pivot в tenant БД и related модели в central БД.
 * Загружает данные через два запроса: pivot на connection родителя, related на connection модели.
 */
class BelongsToManyAcrossConnections extends Relation
{
    protected string $pivotTable;

    protected string $foreignPivotKey;

    protected string $relatedPivotKey;

    protected string $parentKey;

    protected string $relatedKey;

    /** @var Model[] Модели для eager load (сохраняются в addEagerConstraints) */
    protected array $eagerModels = [];

    public function __construct(
        Builder $query,
        Model $parent,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey = 'id',
        string $relatedKey = 'id'
    ) {
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;

        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        // Ограничения добавляются в getResults через pivot
    }

    public function addEagerConstraints(array $models): void
    {
        $this->eagerModels = $models;
    }

    /**
     * Для eager load возвращаем пустую коллекцию — загрузка выполняется в match().
     */
    public function getEager(): Collection
    {
        if (empty($this->eagerModels)) {
            return $this->related->newCollection();
        }
        return $this->related->newCollection();
    }

    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, $relation): array
    {
        $parentKeys = collect($models)->pluck($this->parentKey)->unique()->values()->all();

        if (empty($parentKeys)) {
            return $models;
        }

        $parentConnection = $models[0]->getConnection();
        $pivotQuery = $parentConnection
            ->table($this->pivotTable)
            ->whereIn($this->foreignPivotKey, $parentKeys);

        $pivotRows = $pivotQuery->get([$this->foreignPivotKey, $this->relatedPivotKey]);

        $userIdsByParent = [];
        foreach ($pivotRows as $row) {
            $parentId = $row->{$this->foreignPivotKey};
            $relatedId = $row->{$this->relatedPivotKey};
            $userIdsByParent[$parentId][] = $relatedId;
        }

        $allUserIds = collect($pivotRows)->pluck($this->relatedPivotKey)->unique()->values()->all();
        $usersById = [];
        if (!empty($allUserIds)) {
            $users = $this->query->whereIn($this->relatedKey, $allUserIds)->get();
            foreach ($users as $user) {
                $usersById[$user->getKey()] = $user;
            }
        }

        foreach ($models as $model) {
            $parentId = $model->{$this->parentKey};
            $relatedIds = $userIdsByParent[$parentId] ?? [];
            $relatedModels = [];
            foreach ($relatedIds as $id) {
                if (isset($usersById[$id])) {
                    $relatedModels[] = $usersById[$id];
                }
            }
            $model->setRelation($relation, $this->related->newCollection($relatedModels));
        }

        return $models;
    }

    public function getResults()
    {
        $parentId = $this->parent->getKey();
        if ($parentId === null) {
            return $this->related->newCollection();
        }

        $userIds = $this->parent->getConnection()
            ->table($this->pivotTable)
            ->where($this->foreignPivotKey, $parentId)
            ->pluck($this->relatedPivotKey);

        if ($userIds->isEmpty()) {
            return $this->related->newCollection();
        }

        return $this->query->whereIn($this->relatedKey, $userIds)->get();
    }

    public function get($columns = ['*'])
    {
        return $this->getResults();
    }

    /**
     * whereHas не поддерживается для cross-database (pivot в tenant, related в central).
     * Используйте whereIn + подзапрос к pivot или ручную проверку.
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*'])
    {
        return $query->select($columns)->whereRaw('1 = 0');
    }
}
