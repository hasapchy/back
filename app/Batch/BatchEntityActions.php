<?php

namespace App\Batch;

use App\Events\OrderFirstStageCountUpdated;
use App\Models\Client;
use App\Models\EmployeeSalary;
use App\Models\Order;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\ClientsRepository;
use App\Repositories\OrdersRepository;
use App\Repositories\ProjectsRepository;
use App\Repositories\SalesRepository;
use App\Repositories\TaskRepository;
use App\Repositories\TransactionsRepository;
use App\Repositories\UsersRepository;
use App\Services\CacheService;
use App\Services\TransactionDeleteConstraints;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class BatchEntityActions
{
    public function __construct(
        private readonly ClientsRepository $clientsRepository,
        private readonly SalesRepository $salesRepository,
        private readonly ProjectsRepository $projectsRepository,
        private readonly OrdersRepository $ordersRepository,
        private readonly TaskRepository $taskRepository,
        private readonly UsersRepository $usersRepository,
        private readonly TransactionsRepository $transactionsRepository,
        private readonly TransactionDeleteConstraints $transactionDeleteConstraints,
    ) {}

    public function deleteClient(User $user, int $id): void
    {
        $client = Client::query()->find($id);
        if (! $client) {
            throw new NotFoundHttpException('Клиент не найден');
        }

        Gate::forUser($user)->authorize('delete', $client);

        if (DB::table('transactions')->where('client_id', $id)->exists()) {
            throw new UnprocessableEntityHttpException('Нельзя удалить клиента: найдены связанные транзакции.');
        }

        if (DB::table('orders')->where('client_id', $id)->exists()) {
            throw new UnprocessableEntityHttpException('Нельзя удалить клиента: найдены связанные заказы.');
        }

        $balance = DB::table('clients')->where('id', $id)->value('balance');
        if ($balance > 0 || $balance < 0) {
            throw new UnprocessableEntityHttpException('Нельзя удалить клиента с ненулевым балансом.');
        }

        $deleted = $this->clientsRepository->deleteItem($id);
        if (! $deleted) {
            throw new NotFoundHttpException('Клиент не найден');
        }

        CacheService::invalidateOrdersCache();
        CacheService::invalidateSalesCache();
        CacheService::invalidateTransactionsCache();
    }

    public function deleteSale(User $user, int $id): void
    {
        $sale = $this->salesRepository->getItemById($id);
        if (! $sale) {
            throw new NotFoundHttpException('Продажа не найдена');
        }

        Gate::forUser($user)->authorize('delete', $sale);

        $projectId = $sale->project_id ?? null;

        $this->salesRepository->deleteItem($id);

        CacheService::invalidateSalesCache();
        CacheService::invalidateClientsCache();
        if ($projectId) {
            CacheService::invalidateProjectsCache();
        }
    }

    public function deleteTask(int $id): void
    {
        $this->taskRepository->delete($id);
    }

    public function deleteUser(User $user, int $id): void
    {
        $target = User::query()->find($id);
        if (! $target) {
            throw new NotFoundHttpException('Пользователь не найден');
        }

        Gate::forUser($user)->authorize('delete', $target);

        try {
            $this->usersRepository->deleteItem($id);
        } catch (\Exception $e) {
            throw new HttpException(400, $e->getMessage());
        }
    }

    public function deleteEmployeeSalary(User $user, int $salaryId): void
    {
        $salary = EmployeeSalary::query()->find($salaryId);
        if (! $salary) {
            throw new NotFoundHttpException('Запись зарплаты не найдена');
        }

        Gate::forUser($user)->authorize('delete', $salary);

        if (! $this->usersRepository->deleteSalary($salaryId)) {
            throw new \RuntimeException('Ошибка удаления зарплаты');
        }
    }

    public function deleteTransaction(User $user, int $id): void
    {
        $transaction = Transaction::query()->find($id);
        if (! $transaction) {
            throw new NotFoundHttpException('Транзакция не найдена');
        }

        Gate::forUser($user)->authorize('delete', $transaction);

        $denial = $this->transactionDeleteConstraints->deleteRestrictionMessage($user, $transaction);
        if ($denial !== null) {
            throw new AccessDeniedHttpException($denial);
        }

        if (! $this->transactionsRepository->deleteItem($id)) {
            throw new \RuntimeException('Ошибка удаления транзакции');
        }

        $this->clearTransactionCaches([$transaction]);
    }

    public function updateProjectStatuses(User $user, array $ids, int $statusId): int
    {
        $projects = Project::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $project = $projects->get($id);
            if (! $project) {
                throw new \InvalidArgumentException('Проект ID '.$id.' не найден');
            }
            Gate::forUser($user)->authorize('update', $project);
        }

        return $this->projectsRepository->updateStatusByIds($ids, $statusId);
    }

    public function updateOrderStatuses(User $user, array $ids, int $statusId, ?int $companyId): int|array
    {
        $orders = Order::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $order = $orders->get($id);
            if (! $order) {
                throw new \InvalidArgumentException('Заказ ID '.$id.' не найден');
            }
            Gate::forUser($user)->authorize('update', $order);
        }

        $result = $this->ordersRepository->updateStatusByIds($ids, $statusId);

        if (is_array($result) && ! empty($result['needs_payment'])) {
            return $result;
        }

        $updated = (int) $result;
        if ($updated > 0 && $companyId !== null) {
            event(new OrderFirstStageCountUpdated($companyId));
        }

        return $updated;
    }

    private function clearTransactionCaches(array $transactions): void
    {
        CacheService::invalidateTransactionsCache();
        $hadClient = false;
        $hadProject = false;
        foreach ($transactions as $transaction) {
            if ($transaction->client_id) {
                $hadClient = true;
            }
            if ($transaction->project_id) {
                $hadProject = true;
            }
        }
        if ($hadClient) {
            CacheService::invalidateClientsCache();
        }
        if ($hadProject) {
            CacheService::invalidateProjectsCache();
        }
        CacheService::invalidateCashRegistersCache();
    }
}
