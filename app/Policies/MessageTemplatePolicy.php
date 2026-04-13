<?php

namespace App\Policies;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class MessageTemplatePolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'templates';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр шаблонов');
    }

    public function view(User $user, MessageTemplate $messageTemplate): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $messageTemplate, 'У вас нет прав на просмотр этого шаблона');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание шаблонов');
    }

    public function update(User $user, MessageTemplate $messageTemplate): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $messageTemplate, 'У вас нет прав на редактирование этого шаблона');
    }

    public function delete(User $user, MessageTemplate $messageTemplate): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $messageTemplate, 'У вас нет прав на удаление этого шаблона');
    }
}
