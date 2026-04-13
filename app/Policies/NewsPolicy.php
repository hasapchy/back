<?php

namespace App\Policies;

use App\Models\News;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class NewsPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'news';

    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, News $news): Response
    {
        return Response::allow();
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание новостей');
    }

    public function update(User $user, News $news): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $news, 'У вас нет прав на редактирование этой новости');
    }

    public function delete(User $user, News $news): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $news, 'У вас нет прав на удаление этой новости');
    }
}
