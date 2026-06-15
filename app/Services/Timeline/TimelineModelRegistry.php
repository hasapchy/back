<?php

namespace App\Services\Timeline;

use InvalidArgumentException;

class TimelineModelRegistry
{
    /**
     * @return array{select: list<string>, with: list<string>, merge_order_transaction_logs: bool}
     */
    public static function config(string $modelClass): array
    {
        try {
            return TimelineEntityRegistry::presenterConfig($modelClass);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('Timeline не поддерживается для данной модели', 0, $e);
        }
    }
}
