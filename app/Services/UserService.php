<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UsersRepository;
use App\Services\UserPhotoService;
use Illuminate\Http\Request;

class UserService
{
    /**
     * @var UsersRepository
     */
    protected $repository;

    /**
     * @var UserPhotoService
     */
    protected $photoService;

    /**
     * @param UsersRepository $repository
     * @param UserPhotoService $photoService
     */
    public function __construct(UsersRepository $repository, UserPhotoService $photoService)
    {
        $this->repository = $repository;
        $this->photoService = $photoService;
    }

    /**
     * Создать пользователя
     *
     * @param array $data
     * @param Request $request
     * @return User
     */
    public function createUser(array $data, Request $request): User
    {
        $user = $this->repository->createItem($data);

        if ($request->hasFile('photo')) {
            $photo = $request->file('photo');
            $this->photoService->uploadPhoto($user, $photo);
        }

        return $user;
    }

    /**
     * Обновить пользователя
     *
     * @param User $user
     * @param array $data
     * @param Request $request
     * @return User
     */
    public function updateUser(User $user, array $data, Request $request): User
    {
        unset($data['photo']);

        $hasPosition = array_key_exists('position', $request->all());
        $hasHireDate = array_key_exists('hire_date', $request->all());
        $hasBirthday = array_key_exists('birthday', $request->all());

        $companies = $data['companies'] ?? null;
        $roles = $data['roles'] ?? null;
        $companyRoles = $data['company_roles'] ?? null;
        $position = $data['position'] ?? null;
        $hireDate = $data['hire_date'] ?? null;
        $birthday = $data['birthday'] ?? null;

        $data = array_filter($data, function ($value) {
            return $value !== null;
        });

        if ($companies !== null) {
            $data['companies'] = $companies;
        }
        if ($roles !== null) {
            $data['roles'] = $roles;
        }
        if ($companyRoles !== null) {
            $data['company_roles'] = $companyRoles;
        }
        if ($hasPosition) {
            $data['position'] = $position;
        }
        if ($hasHireDate) {
            $data['hire_date'] = $hireDate;
        }
        if ($hasBirthday) {
            $data['birthday'] = $birthday;
        }

        $user = $this->repository->updateItem($user->id, $data);

        if ($request->hasFile('photo')) {
            $photoPath = $this->photoService->updatePhoto($user, $request->file('photo'));
            if ($photoPath) {
                $user = $this->repository->updateItem($user->id, ['photo' => $photoPath]);
            }
        } elseif ($request->has('photo') && ($request->input('photo') === '' || $request->input('photo') === null)) {
            $this->photoService->deletePhoto($user);
            $user = $this->repository->updateItem($user->id, ['photo' => '']);
        }

        return $user;
    }

    /**
     * Подготовить данные пользователя из запроса
     *
     * @param Request $request
     * @return array
     */
    public function prepareUserData(Request $request): array
    {
        $data = $request->all();

        if (isset($data['is_active'])) {
            $data['is_active'] = filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['is_admin'])) {
            $data['is_admin'] = filter_var($data['is_admin'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['roles']) && is_string($data['roles'])) {
            $data['roles'] = explode(',', $data['roles']);
        }

        if (isset($data['companies'])) {
            if (is_string($data['companies'])) {
                $data['companies'] = array_filter(explode(',', $data['companies']), function ($c) {
                    return trim($c) !== '';
                });
            }
            if (is_array($data['companies'])) {
                $data['companies'] = array_values(array_map('intval', $data['companies']));
            }
        }

        if (isset($data['company_roles']) && is_string($data['company_roles'])) {
            try {
                $data['company_roles'] = json_decode($data['company_roles'], true);
            } catch (\Exception $e) {
                $data['company_roles'] = [];
            }
        }

        if (isset($data['position']) && trim($data['position']) === '') {
            $data['position'] = null;
        }
        if (isset($data['hire_date']) && trim($data['hire_date']) === '') {
            $data['hire_date'] = null;
        }
        if (isset($data['birthday']) && trim($data['birthday']) === '') {
            $data['birthday'] = null;
        }

        return $data;
    }
}

