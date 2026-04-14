<?php

namespace App\Batch;

use App\Batch\Contracts\BatchStrategyInterface;
use App\Batch\Strategies\BulkBatchStrategy;
use App\Batch\Strategies\QueueBatchStrategy;
use App\Batch\Strategies\TransactionLoopBatchStrategy;
use App\Models\User;
use App\Support\CompanyScopedPermissionGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class BatchService
{
    public function __construct(
        private readonly BatchOperationRegistry $registry,
        private readonly BatchStrategyResolver $resolver,
        private readonly BulkBatchStrategy $bulkStrategy,
        private readonly TransactionLoopBatchStrategy $loopStrategy,
        private readonly QueueBatchStrategy $queueStrategy,
    ) {}

    public function execute(
        array $input,
        User $user,
        bool $forceSync = false,
        ?int $resolvedCompanyId = null,
        ?string $idempotencyKey = null,
    ): BatchResult {
        $clientForceSync = isset($input['sync']) && filter_var($input['sync'], FILTER_VALIDATE_BOOLEAN);
        unset($input['sync']);
        $forceSync = $forceSync || $clientForceSync;

        $entity = isset($input['entity']) ? (string) $input['entity'] : '';
        $action = isset($input['action']) ? (string) $input['action'] : '';
        $ids = isset($input['ids']) && is_array($input['ids']) ? $input['ids'] : [];
        $payload = isset($input['payload']) && is_array($input['payload']) ? $input['payload'] : [];

        $validator = Validator::make(
            [
                'entity' => $entity,
                'action' => $action,
                'ids' => $ids,
            ],
            [
                'entity' => 'required|string|max:128',
                'action' => 'required|string|max:128',
                'ids' => 'required|array|min:1|max:'.(int) config('batch.max_ids', 50),
                'ids.*' => 'integer|distinct',
            ],
        );
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        $cacheKey = null;
        if ($idempotencyKey !== null && $idempotencyKey !== '' && ! $forceSync) {
            $cacheKey = $this->idempotencyCacheKey($user, $idempotencyKey, $entity, $action, $ids, $payload);
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return BatchResult::fromArray($cached);
            }
        }

        $operation = $this->registry->get($entity, $action);

        if ($operation->scopePermissionsAny !== []) {
            if (! CompanyScopedPermissionGate::allowsAny($user, $resolvedCompanyId, $operation->scopePermissionsAny)) {
                throw new AccessDeniedHttpException('Forbidden batch operation');
            }
        } elseif (! $this->userPassesPermissions($user, $operation->permissionsAny)) {
            throw new AccessDeniedHttpException('Forbidden batch operation');
        }

        $kind = $this->resolver->resolve($operation, count($ids), $forceSync);
        $strategy = $this->strategyFor($kind);

        $result = $strategy->run($operation, $ids, $payload, $user, $resolvedCompanyId, $forceSync);

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $result->toArray(), (int) config('batch.idempotency_ttl_seconds', 86400));
        }

        return $result;
    }

    private function strategyFor(BatchStrategyKind $kind): BatchStrategyInterface
    {
        return match ($kind) {
            BatchStrategyKind::Bulk => $this->bulkStrategy,
            BatchStrategyKind::Loop => $this->loopStrategy,
            BatchStrategyKind::Queue => $this->queueStrategy,
        };
    }

    private function userPassesPermissions(User $user, array $permissionsAny): bool
    {
        if ($user->is_admin) {
            return true;
        }
        if ($permissionsAny === []) {
            return false;
        }
        foreach ($permissionsAny as $permission) {
            if ($user->can((string) $permission)) {
                return true;
            }
        }

        return false;
    }

    private function idempotencyCacheKey(User $user, string $idempotencyKey, string $entity, string $action, array $ids, array $payload): string
    {
        $payload = [
            'uid' => $user->getAuthIdentifier(),
            'key' => $idempotencyKey,
            'entity' => $entity,
            'action' => $action,
            'ids' => $ids,
            'payload' => $payload,
        ];

        return 'batch:idempotency:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
