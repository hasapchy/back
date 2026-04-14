<?php

namespace App\Batch\Exceptions;

use RuntimeException;

final class UnknownBatchOperationException extends RuntimeException
{
    public static function for(string $entity, string $action): self
    {
        return new self("Unknown batch operation: {$entity}.{$action}");
    }
}
