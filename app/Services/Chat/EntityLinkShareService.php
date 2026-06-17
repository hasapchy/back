<?php

namespace App\Services\Chat;

use App\Models\CashRegister;
use App\Models\Order;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\User;
use App\Support\CompanyScopedPermissions;
use App\Support\ResolvedCompany;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;

class EntityLinkShareService
{
    public const METADATA_TYPE = 'entity_link';

    public const RESERVED_METADATA_TYPES = [
        self::METADATA_TYPE,
    ];

    private const METADATA_VERSION = 1;

    /**
     * @var array<string, string>
     */
    private const ENTITY_URL_PATTERNS = [
        'transaction' => '/(?:https?:\/\/[^\s\/]+)?\/transactions\/(\d+)/',
        'order' => '/(?:https?:\/\/[^\s\/]+)?\/orders\/(\d+)/',
        'project' => '/(?:https?:\/\/[^\s\/]+)?\/projects\/(\d+)/',
    ];

    /**
     * @param  array<string, mixed>|null  $rawMetadata
     */
    public function rejectClientReservedMetadata(?array $rawMetadata): void
    {
        if ($rawMetadata === null || $rawMetadata === []) {
            return;
        }

        $type = $rawMetadata['type'] ?? null;
        if (is_string($type) && in_array($type, self::RESERVED_METADATA_TYPES, true)) {
            $this->denyEntityLinkAccess();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function buildMetadataFromBody(User $user, string $body): ?array
    {
        $parsed = $this->parseEntityLinkFromBody($body);
        if ($parsed === null) {
            return null;
        }

        return $this->buildStorageMetadata($parsed['entity'], $parsed['entity_id'], $user);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function preview(User $user, string $entity, int $entityId): ?array
    {
        return match ($entity) {
            'transaction' => $this->buildTransactionPreviewMetadata($user, $entityId),
            'order' => $this->buildOrderPreviewMetadata($user, $entityId),
            'project' => $this->buildProjectPreviewMetadata($user, $entityId),
            default => null,
        };
    }

    public function canUserAccessEntity(User $user, string $entity, int $entityId): bool
    {
        return match ($entity) {
            'transaction' => $this->canUserAccessTransaction($user, $entityId),
            'order' => $this->canUserAccessOrder($user, $entityId),
            'project' => $this->canUserAccessProject($user, $entityId),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public function sanitizeMetadataForViewer(?array $metadata, User $user): ?array
    {
        if (! is_array($metadata) || ($metadata['type'] ?? null) !== self::METADATA_TYPE) {
            return $metadata;
        }

        $entity = (string) ($metadata['entity'] ?? '');
        $entityId = (int) ($metadata['entity_id'] ?? 0);
        if ($entity === '' || $entityId <= 0) {
            return $metadata;
        }

        if ($this->canUserAccessEntity($user, $entity, $entityId)) {
            return $metadata;
        }

        return $this->redactedEntityLinkMetadata($metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public function broadcastMetadata(?array $metadata): ?array
    {
        if (! is_array($metadata) || ($metadata['type'] ?? null) !== self::METADATA_TYPE) {
            return $metadata;
        }

        return $this->redactedEntityLinkMetadata($metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function redactedEntityLinkMetadata(array $metadata): array
    {
        $entity = (string) ($metadata['entity'] ?? '');
        $entityId = (int) ($metadata['entity_id'] ?? 0);
        $base = match ($entity) {
            'transaction' => $this->minimalTransactionMetadata($entityId),
            'order' => $this->minimalOrderMetadata($entityId),
            'project' => $this->minimalProjectMetadata($entityId),
            default => [
                'v' => self::METADATA_VERSION,
                'type' => self::METADATA_TYPE,
                'entity' => $entity,
                'entity_id' => $entityId,
                'url' => '',
                'icon' => 'fas fa-link',
            ],
        };

        return array_merge($base, ['restricted' => true]);
    }

    /**
     * @return array{entity: string, entity_id: int}|null
     */
    private function parseEntityLinkFromBody(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        foreach (self::ENTITY_URL_PATTERNS as $entity => $pattern) {
            if (preg_match($pattern, $body, $matches) !== 1) {
                continue;
            }

            $entityId = (int) ($matches[1] ?? 0);
            if ($entityId > 0) {
                return [
                    'entity' => $entity,
                    'entity_id' => $entityId,
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStorageMetadata(string $entity, int $entityId, User $user): array
    {
        return match ($entity) {
            'transaction' => $this->buildTransactionStorageMetadata($user, $entityId),
            'order' => $this->buildOrderStorageMetadata($user, $entityId),
            'project' => $this->buildProjectStorageMetadata($user, $entityId),
            default => abort(422, __('api.entity_link.unsupported_entity')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderStorageMetadata(User $user, int $entityId): array
    {
        if (! $this->canUserAccessOrder($user, $entityId)) {
            $this->denyEntityLinkAccess();
        }

        return $this->minimalOrderMetadata($entityId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildOrderPreviewMetadata(User $user, int $entityId): ?array
    {
        $order = $this->findOrderInCurrentCompany($entityId);
        if (! $order || ! $this->userCanViewOrder($user, $order)) {
            return null;
        }

        return array_merge(
            $this->minimalOrderMetadata($entityId),
            $this->orderDetailFields($order, $entityId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalOrderMetadata(int $entityId): array
    {
        return [
            'v' => self::METADATA_VERSION,
            'type' => self::METADATA_TYPE,
            'entity' => 'order',
            'entity_id' => $entityId,
            'url' => '/orders/'.$entityId,
            'icon' => 'fas fa-clipboard-list',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderDetailFields(Order $order, int $entityId): array
    {
        $currencyCode = $order->currency?->code ?? $order->currency?->symbol ?? '';
        $amount = number_format((float) $order->total_price, 2, '.', ' ');
        $subtitle = trim($amount.' '.$currencyCode);
        $clientName = trim(trim((string) ($order->client?->first_name ?? '')).' '.trim((string) ($order->client?->last_name ?? '')));
        if ($clientName !== '') {
            $subtitle = $subtitle !== '' ? $subtitle.' · '.$clientName : $clientName;
        }

        return [
            'title' => __('api.entity_link.order_title', ['id' => $entityId]),
            'subtitle' => $subtitle,
        ];
    }

    private function canUserAccessOrder(User $user, int $entityId): bool
    {
        $order = $this->findOrderInCurrentCompany($entityId);

        return $order !== null && $this->userCanViewOrder($user, $order);
    }

    private function findOrderInCurrentCompany(int $entityId): ?Order
    {
        $companyId = ResolvedCompany::fromRequest(request());
        $query = Order::query()
            ->with(['currency:id,code,symbol', 'client:id,first_name,last_name', 'cashRegister:id,company_id']);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->find($entityId);
    }

    private function userCanViewOrder(User $user, Order $order): bool
    {
        if (! Gate::forUser($user)->allows('view', $order)) {
            return false;
        }

        if (! $order->cash_id) {
            return true;
        }

        $cashRegister = $order->relationLoaded('cashRegister')
            ? $order->cashRegister
            : CashRegister::query()->find($order->cash_id);

        if (! $cashRegister) {
            return false;
        }

        return Gate::forUser($user)->allows('view', $cashRegister);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProjectStorageMetadata(User $user, int $entityId): array
    {
        if (! $this->canUserAccessProject($user, $entityId)) {
            $this->denyEntityLinkAccess();
        }

        return $this->minimalProjectMetadata($entityId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildProjectPreviewMetadata(User $user, int $entityId): ?array
    {
        $project = $this->findProjectInCurrentCompany($entityId);
        if (! $project || ! $this->userCanViewProject($user, $project)) {
            return null;
        }

        return array_merge(
            $this->minimalProjectMetadata($entityId),
            $this->projectDetailFields($project, $entityId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalProjectMetadata(int $entityId): array
    {
        return [
            'v' => self::METADATA_VERSION,
            'type' => self::METADATA_TYPE,
            'entity' => 'project',
            'entity_id' => $entityId,
            'url' => '/projects/'.$entityId,
            'icon' => 'fas fa-diagram-project',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function projectDetailFields(Project $project, int $entityId): array
    {
        $subtitle = trim((string) ($project->name ?? ''));
        if ($subtitle === '') {
            $subtitle = (string) $entityId;
        }

        return [
            'title' => __('api.entity_link.project_title', ['id' => $entityId]),
            'subtitle' => $subtitle,
        ];
    }

    private function canUserAccessProject(User $user, int $entityId): bool
    {
        $project = $this->findProjectInCurrentCompany($entityId);

        return $project !== null && $this->userCanViewProject($user, $project);
    }

    private function findProjectInCurrentCompany(int $entityId): ?Project
    {
        $companyId = ResolvedCompany::fromRequest(request());
        $query = Project::query()->select(['id', 'name', 'company_id']);

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query->find($entityId);
    }

    private function userCanViewProject(User $user, Project $project): bool
    {
        return Gate::forUser($user)->allows('view', $project);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTransactionStorageMetadata(User $user, int $entityId): array
    {
        if (! $this->canUserAccessTransaction($user, $entityId)) {
            $this->denyEntityLinkAccess();
        }

        return $this->minimalTransactionMetadata($entityId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildTransactionPreviewMetadata(User $user, int $entityId): ?array
    {
        $transaction = $this->findTransactionInCurrentCompany($entityId);
        if (! $transaction || ! $this->userCanViewTransaction($user, $transaction)) {
            return null;
        }

        return array_merge(
            $this->minimalTransactionMetadata($entityId),
            $this->transactionDetailFields($transaction, $entityId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalTransactionMetadata(int $entityId): array
    {
        return [
            'v' => self::METADATA_VERSION,
            'type' => self::METADATA_TYPE,
            'entity' => 'transaction',
            'entity_id' => $entityId,
            'url' => '/transactions/'.$entityId,
            'icon' => 'fas fa-exchange-alt',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionDetailFields(Transaction $transaction, int $entityId): array
    {
        $typeLabel = (int) $transaction->type === 1
            ? __('api.entity_link.income')
            : __('api.entity_link.outcome');
        $currencyCode = $transaction->currency?->code ?? $transaction->currency?->symbol ?? '';
        $amount = number_format((float) $transaction->orig_amount, 2, '.', ' ');
        $subtitle = trim($typeLabel.' · '.trim($amount.' '.$currencyCode));
        $note = trim((string) ($transaction->note ?? ''));
        if ($note !== '') {
            $subtitle .= ' · '.$note;
        }

        return [
            'title' => __('api.entity_link.transaction_title', ['id' => $entityId]),
            'subtitle' => $subtitle,
        ];
    }

    private function canUserAccessTransaction(User $user, int $entityId): bool
    {
        $transaction = $this->findTransactionInCurrentCompany($entityId);

        return $transaction !== null && $this->userCanViewTransaction($user, $transaction);
    }

    private function findTransactionInCurrentCompany(int $entityId): ?Transaction
    {
        $companyId = ResolvedCompany::fromRequest(request());
        $query = Transaction::query()
            ->with(['currency:id,code,symbol', 'cashRegister:id,company_id']);

        if ($companyId !== null) {
            $query->whereHas('cashRegister', function ($cashRegisterQuery) use ($companyId): void {
                $cashRegisterQuery->where('company_id', $companyId);
            });
        }

        return $query->find($entityId);
    }

    private function userCanViewTransaction(User $user, Transaction $transaction): bool
    {
        $permissions = $this->permissionsForUser($user);
        if ($user->is_admin) {
            return $this->userCanAccessTransactionCashRegister($user, $transaction, $permissions);
        }

        $hasViewAll = in_array('transactions_view_all', $permissions, true);
        $hasViewOwn = in_array('transactions_view_own', $permissions, true);
        if (! $hasViewAll && ! $hasViewOwn) {
            return false;
        }

        if (! $hasViewAll && (int) $transaction->creator_id !== (int) $user->id) {
            return false;
        }

        return $this->userCanAccessTransactionCashRegister($user, $transaction, $permissions);
    }

    /**
     * @return array<int, string>
     */
    private function permissionsForUser(User $user): array
    {
        return CompanyScopedPermissions::namesForCompany(
            $user,
            ResolvedCompany::fromRequest(request())
        );
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userCanAccessTransactionCashRegister(User $user, Transaction $transaction, array $permissions): bool
    {
        if (! $transaction->cash_id) {
            return true;
        }

        if (in_array('cash_registers_view_all', $permissions, true)) {
            return true;
        }

        $cashRegister = $transaction->relationLoaded('cashRegister')
            ? $transaction->cashRegister
            : CashRegister::query()->find($transaction->cash_id);

        if (! $cashRegister) {
            return false;
        }

        return $cashRegister->hasUser($user->id);
    }

    /**
     * @return never
     */
    private function denyEntityLinkAccess(): void
    {
        throw new HttpResponseException(response()->json([
            'error' => __('api.common.not_found'),
        ], 404));
    }
}
