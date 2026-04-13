<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class TemplatePolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'transaction_templates';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр шаблонов транзакций');
    }

    public function view(User $user, Template $template): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $template, 'У вас нет прав на просмотр этого шаблона');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание шаблонов транзакций');
    }

    public function update(User $user, Template $template): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $template, 'У вас нет прав на редактирование этого шаблона');
    }

    public function delete(User $user, Template $template): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $template, 'У вас нет прав на удаление этого шаблона');
    }
}
