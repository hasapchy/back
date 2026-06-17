<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;
use App\Policies\Concerns\AuthorizesResourcePermissions;
use Illuminate\Auth\Access\Response;

class JournalEntryPolicy
{
    use AuthorizesResourcePermissions;

    private const RESOURCE = 'journal_entries';

    /**
     * @param  User  $user
     * @return Response
     */
    public function viewAny(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', null, 'У вас нет прав на просмотр проводок');
    }

    /**
     * @param  User  $user
     * @param  JournalEntry  $journalEntry
     * @return Response
     */
    public function view(User $user, JournalEntry $journalEntry): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'view', $journalEntry, 'У вас нет прав на просмотр этой проводки');
    }

    /**
     * @param  User  $user
     * @return Response
     */
    public function create(User $user): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'create', null, 'У вас нет прав на создание проводок');
    }

    /**
     * @param  User  $user
     * @param  JournalEntry  $journalEntry
     * @return Response
     */
    public function update(User $user, JournalEntry $journalEntry): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'update', $journalEntry, 'У вас нет прав на редактирование этой проводки');
    }

    /**
     * @param  User  $user
     * @param  JournalEntry  $journalEntry
     * @return Response
     */
    public function delete(User $user, JournalEntry $journalEntry): Response
    {
        return $this->resourceAbilityResponse($user, self::RESOURCE, 'delete', $journalEntry, 'У вас нет прав на удаление этой проводки');
    }
}
