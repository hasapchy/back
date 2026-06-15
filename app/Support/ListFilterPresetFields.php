<?php

namespace App\Support;

use App\Enums\ListFilterPresetSource;
use InvalidArgumentException;

class ListFilterPresetFields
{
    /**
     * @return array<int, string>
     */
    public static function keysFor(ListFilterPresetSource $source): array
    {
        return match ($source) {
            ListFilterPresetSource::Transactions => [
                'cashRegisterId',
                'dateFilter',
                'startDate',
                'endDate',
                'transactionTypeFilter',
                'sourceFilter',
                'projectId',
                'debtFilter',
                'categoryFilter',
            ],
            ListFilterPresetSource::Orders => [
                'dateFilter',
                'startDate',
                'endDate',
                'statusFilter',
                'projectFilter',
                'clientFilter',
                'categoryFilter',
            ],
            ListFilterPresetSource::Projects => [
                'statusFilter',
                'clientFilter',
            ],
            ListFilterPresetSource::Contracts => [
                'projectFilter',
                'projectStatusFilter',
                'paymentStatusFilter',
                'lifecycleStatusFilter',
                'contractStatusFilter',
                'cashRegisterFilter',
                'typeFilter',
            ],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultsFor(ListFilterPresetSource $source): array
    {
        return match ($source) {
            ListFilterPresetSource::Transactions => [
                'cashRegisterId' => '',
                'dateFilter' => 'this_month',
                'startDate' => null,
                'endDate' => null,
                'transactionTypeFilter' => '',
                'sourceFilter' => '',
                'projectId' => '',
                'debtFilter' => 'all',
                'categoryFilter' => [],
            ],
            ListFilterPresetSource::Orders => [
                'dateFilter' => 'all_time',
                'startDate' => null,
                'endDate' => null,
                'statusFilter' => '',
                'projectFilter' => '',
                'clientFilter' => '',
                'categoryFilter' => '',
            ],
            ListFilterPresetSource::Projects => [
                'statusFilter' => '',
                'clientFilter' => '',
            ],
            ListFilterPresetSource::Contracts => [
                'projectFilter' => '',
                'projectStatusFilter' => '',
                'paymentStatusFilter' => '',
                'lifecycleStatusFilter' => '',
                'contractStatusFilter' => '',
                'cashRegisterFilter' => '',
                'typeFilter' => '',
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public static function ignoredKeysInKanbanFor(ListFilterPresetSource $source): array
    {
        return match ($source) {
            ListFilterPresetSource::Orders,
            ListFilterPresetSource::Projects => ['statusFilter'],
            default => [],
        };
    }

    /**
     * @return array{keys: array<int, string>, defaults: array<string, mixed>, ignoredKeysInKanban: array<int, string>}
     */
    public static function schemaFor(ListFilterPresetSource $source): array
    {
        return [
            'keys' => self::keysFor($source),
            'defaults' => self::defaultsFor($source),
            'ignoredKeysInKanban' => self::ignoredKeysInKanbanFor($source),
            'appearance' => ListFilterPresetAppearance::schema(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function mergeWithDefaults(ListFilterPresetSource $source, array $filters): array
    {
        self::assertOnlyAllowedKeys($source, $filters);

        return array_merge(self::defaultsFor($source), $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function assertOnlyAllowedKeys(ListFilterPresetSource $source, array $filters): void
    {
        $allowed = self::keysFor($source);
        foreach (array_keys($filters) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException("Unknown filter key: {$key}");
            }
        }
    }

    /**
     * @return ListFilterPresetSource
     */
    public static function parseSource(string $value): ListFilterPresetSource
    {
        return ListFilterPresetSource::from($value);
    }
}
