<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class LeadPolicy
{
    use AuthorizesResourcePermissions;

    /**
     * @return Response
     */
    public function viewAny(User $user): Response
    {
        return $this->respond($user, 'view', null, false);
    }

    /**
     * @return Response
     */
    public function view(User $user, Lead $lead): Response
    {
        return $this->respond($user, 'view', $lead, true);
    }

    /**
     * @return Response
     */
    public function create(User $user): Response
    {
        return $this->respond($user, 'create', null, false);
    }

    /**
     * @return Response
     */
    public function update(User $user, Lead $lead): Response
    {
        return $this->respond($user, 'update', $lead, true);
    }

    /**
     * @return Response
     */
    public function delete(User $user, Lead $lead): Response
    {
        return $this->respond($user, 'delete', $lead, true);
    }

    /**
     * @return Response
     */
    private function respond(User $user, string $action, ?Lead $lead, bool $forSingleModel): Response
    {
        return $this->resourceAbilityResponse(
            $user,
            'leads',
            $action,
            $lead,
            $this->denyMessage($action, $forSingleModel)
        );
    }

    /**
     * @return string
     */
    private function denyMessage(string $action, bool $forSingleModel): string
    {
        return match ($action) {
            'view' => $forSingleModel
                ? 'У вас нет прав на просмотр этого лида'
                : 'У вас нет прав на просмотр лидов',
            'create' => 'У вас нет прав на создание лидов',
            'update' => 'У вас нет прав на редактирование этого лида',
            'delete' => 'У вас нет прав на удаление этого лида',
            default => 'Доступ запрещён',
        };
    }
}
