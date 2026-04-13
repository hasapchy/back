<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use App\Support\SimpleUser;
use Illuminate\Auth\Access\Response;

class OrderPolicy
{
    use AuthorizesResourcePermissions;

    public function viewAny(User $user): Response
    {
        return $this->respond($user, 'view', null, false);
    }

    public function view(User $user, Order $order): Response
    {
        return $this->respond($user, 'view', $order, true);
    }

    public function create(User $user): Response
    {
        return $this->respond($user, 'create', null, false);
    }

    public function update(User $user, Order $order): Response
    {
        return $this->respond($user, 'update', $order, true);
    }

    public function delete(User $user, Order $order): Response
    {
        return $this->respond($user, 'delete', $order, true);
    }

    private function respond(User $user, string $action, ?Order $order, bool $forSingleModel): Response
    {
        $resource = SimpleUser::ordersPermissionResource($user);

        return $this->resourceAbilityResponse(
            $user,
            $resource,
            $action,
            $order,
            $this->denyMessage($action, $forSingleModel)
        );
    }

    private function denyMessage(string $action, bool $forSingleModel): string
    {
        return match ($action) {
            'view' => $forSingleModel
                ? 'У вас нет прав на просмотр этого заказа'
                : 'У вас нет прав на просмотр заказов',
            'create' => 'У вас нет прав на создание заказов',
            'update' => 'У вас нет прав на редактирование этого заказа',
            'delete' => 'У вас нет прав на удаление этого заказа',
            default => 'Доступ запрещён',
        };
    }
}
