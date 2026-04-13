<?php

namespace App\Policies;

use App\Models\RecSchedule;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RecSchedulePolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, RecSchedule $schedule): Response
    {
        if ($user->is_admin) {
            return Response::allow();
        }

        if ($user->can('rec_schedules_view_all')) {
            return Response::allow();
        }

        return (int) $schedule->creator_id === (int) $user->id
            ? Response::allow()
            : Response::deny('Нет прав на просмотр этого расписания');
    }

    public function create(User $user): Response
    {
        return $user->can('rec_schedules_create')
            ? Response::allow()
            : Response::deny('Нет прав на создание повторяющихся транзакций');
    }

    public function update(User $user, RecSchedule $schedule): Response
    {
        if ($user->is_admin) {
            return Response::allow();
        }

        if ($user->can('rec_schedules_update_all')) {
            return Response::allow();
        }

        if ($user->can('rec_schedules_update') && (int) $schedule->creator_id === (int) $user->id) {
            return Response::allow();
        }

        return Response::deny('Нет прав на редактирование этого расписания');
    }

    public function delete(User $user, RecSchedule $schedule): Response
    {
        if ($user->is_admin) {
            return Response::allow();
        }

        if ($user->can('rec_schedules_delete_all')) {
            return Response::allow();
        }

        if ($user->can('rec_schedules_delete') && (int) $schedule->creator_id === (int) $user->id) {
            return Response::allow();
        }

        return Response::deny('Нет прав на удаление этого расписания');
    }
}
