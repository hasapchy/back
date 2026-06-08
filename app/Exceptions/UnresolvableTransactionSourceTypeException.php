<?php

namespace App\Exceptions;

use Exception;

class UnresolvableTransactionSourceTypeException extends Exception
{
    /**
     * @param string|null $sourceType
     */
    public function __construct(?string $sourceType)
    {
        parent::__construct(
            'Unresolvable transaction source type for rounding: '.($sourceType ?? 'null')
        );
    }
}
