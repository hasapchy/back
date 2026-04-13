<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class TransactionPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'transactions';

    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр транзакций');
    }

    public function view(User $user, Transaction $transaction): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $transaction, 'У вас нет прав на просмотр этой транзакции');
    }

    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание транзакций');
    }

    public function update(User $user, Transaction $transaction): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $transaction, 'У вас нет прав на редактирование этой транзакции');
    }

    public function delete(User $user, Transaction $transaction): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $transaction, 'У вас нет прав на удаление этой транзакции');
    }
}
