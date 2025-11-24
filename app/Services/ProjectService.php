<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Repositories\ProjectsRepository;
use Illuminate\Http\Request;

class ProjectService
{
    /**
     * @var ProjectsRepository
     */
    protected $repository;

    /**
     * @param ProjectsRepository $repository
     */
    public function __construct(ProjectsRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Создать проект
     *
     * @param array $data
     * @param User $user
     * @return Project
     */
    public function createProject(array $data, User $user): Project
    {
        $data['user_id'] = $user->id;
        $data['status_id'] = $data['status_id'] ?? 1;

        $created = $this->repository->createItem($data);

        if (!$created) {
            throw new \Exception('Ошибка создания проекта');
        }

        return $created;
    }

    /**
     * Обновить проект
     *
     * @param Project $project
     * @param array $data
     * @param User $user
     * @return Project
     */
    public function updateProject(Project $project, array $data, User $user): Project
    {
        $data['user_id'] = $user->id;

        $updated = $this->repository->updateItem($project->id, $data);

        if (!$updated) {
            throw new \Exception('Ошибка обновления проекта');
        }

        return $updated;
    }

    /**
     * Подготовить данные проекта из запроса
     *
     * @param Request $request
     * @param User $user
     * @return array
     */
    public function prepareProjectData(Request $request, User $user): array
    {
        $data = [
            'name' => $request->name,
            'date' => $request->date,
            'user_id' => $user->id,
            'client_id' => $request->client_id,
            'users' => $request->users,
            'description' => $request->description,
        ];

        if ($request->has('budget')) {
            $data['budget'] = $request->budget;
        }
        if ($request->has('currency_id')) {
            $data['currency_id'] = $request->currency_id;
        }
        if ($request->has('exchange_rate')) {
            $data['exchange_rate'] = $request->exchange_rate;
        }

        return $data;
    }
}

