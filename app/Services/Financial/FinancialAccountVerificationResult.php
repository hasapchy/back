<?php

namespace App\Services\Financial;

class FinancialAccountVerificationResult
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $errors = [],
    ) {}

    /**
     * @param  string  $error
     * @return self
     */
    public static function fail(string $error): self
    {
        return new self(false, [$error]);
    }

    /**
     * @return self
     */
    public static function pass(): self
    {
        return new self(true);
    }

    /**
     * @param  FinancialAccountVerificationResult  $other
     * @return self
     */
    public function merge(self $other): self
    {
        if ($other->passed && $this->passed) {
            return $this;
        }

        return new self(false, array_values(array_unique(array_merge($this->errors, $other->errors))));
    }
}
