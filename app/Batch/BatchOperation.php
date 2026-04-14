<?php

namespace App\Batch;

final class BatchOperation
{
    public function __construct(
        public string $entity,
        public string $action,
        public bool $financial,
        public array $permissionsAny,
        public string $preferredStrategy = 'auto',
        public ?\Closure $bulkHandler = null,
        public ?\Closure $loopHandler = null,
        public bool $allowPartialFailure = true,
        public array $scopePermissionsAny = [],
    ) {}

    public function key(): string
    {
        return strtolower($this->entity).'.'.strtolower($this->action);
    }
}
