<?php

namespace App\Support;

final class ReferencePayloadBudget
{
    /**
     * @param  mixed  $payload
     */
    public static function jsonEncodedByteLength(mixed $payload): int
    {
        return strlen(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return int|null Лимит в байтах или null, если ключ не задан
     */
    public static function limitFor(string $budgetKey): ?int
    {
        $budgets = config('reference_contracts.payload_budget_bytes', []);
        if (! array_key_exists($budgetKey, $budgets)) {
            return null;
        }

        return (int) $budgets[$budgetKey];
    }
}
