<?php

namespace App\Batch;

use App\Batch\Exceptions\UnknownBatchOperationException;

final class BatchOperationRegistry
{
    /** @var array<string, BatchOperation> */
    private array $operations = [];

    public function register(BatchOperation $operation): void
    {
        $this->operations[$operation->key()] = $operation;
    }

    public function get(string $entity, string $action): BatchOperation
    {
        $key = strtolower($entity).'.'.strtolower($action);
        if (! isset($this->operations[$key])) {
            throw UnknownBatchOperationException::for($entity, $action);
        }

        return $this->operations[$key];
    }
}
