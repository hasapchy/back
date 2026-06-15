<?php

namespace App\Support\ActivityLog;

use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class ActivityPropertiesNormalizer
{
    private const CRUD_EVENTS = ['created', 'updated', 'deleted'];

    private const PROTECTED_DESCRIPTION_KEYS = [
        'activity_log.order.products_updated',
        'activity_log.wh_receipt.products_updated',
        'activity_log.wh_writeoff.products_updated',
        'activity_log.wh_movement.products_updated',
        'activity_log.wh_purchase.products_updated',
        'activity_log.sale.products_updated',
        'activity_log.inventory.items_counted',
        'activity_log.project_contract.returned_signed',
        'activity_log.project_contract.returned_unsigned',
    ];

    private const DEPRECATED_LOG_NAMES = [
        'order_transaction',
    ];

    /**
     * @param mixed $properties
     * @return array<string, mixed>
     */
    public static function toArray(mixed $properties): array
    {
        if ($properties instanceof Collection) {
            return $properties->all();
        }

        if (is_array($properties)) {
            return $properties;
        }

        if (is_object($properties) && method_exists($properties, 'toArray')) {
            $array = $properties->toArray();

            return is_array($array) ? $array : [];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $properties
     * @return bool
     */
    public static function isCustomPayload(array $properties): bool
    {
        if ($properties === []) {
            return false;
        }

        if (array_key_exists('added', $properties)
            || array_key_exists('removed', $properties)
            || array_key_exists('updated', $properties)
            || array_key_exists('counted', $properties)) {
            return true;
        }

        if (array_key_exists('diff', $properties) || array_key_exists('attrs', $properties)) {
            return false;
        }

        return ! array_key_exists('attributes', $properties)
            && ! array_key_exists('old', $properties);
    }

    /**
     * @param array<string, mixed> $properties
     * @param string|null $event
     * @return array<string, mixed>
     */
    public static function compress(array $properties, ?string $event): array
    {
        if (self::isCustomPayload($properties)) {
            return $properties;
        }

        if (array_key_exists('diff', $properties) || array_key_exists('attrs', $properties)) {
            return $properties;
        }

        $attributes = $properties['attributes'] ?? null;
        $old = $properties['old'] ?? null;

        if ($event === 'updated' && is_array($attributes) && is_array($old)) {
            $diff = [];
            foreach ($attributes as $field => $to) {
                $diff[$field] = [
                    'from' => $old[$field] ?? null,
                    'to' => $to,
                ];
            }

            return $diff === [] ? [] : ['diff' => $diff];
        }

        if ($event === 'deleted' && is_array($old) && $attributes === null) {
            return $old === [] ? [] : ['attrs' => $old];
        }

        if (is_array($attributes)) {
            return $attributes === [] ? [] : ['attrs' => $attributes];
        }

        if (is_array($old)) {
            return $old === [] ? [] : ['attrs' => $old];
        }

        return $properties;
    }

    /**
     * @param mixed $properties
     * @return array{attributes: ?array<string, mixed>, old: ?array<string, mixed>}
     */
    public static function expand(mixed $properties): array
    {
        $props = self::toArray($properties);

        if ($props === [] || self::isCustomPayload($props)) {
            return ['attributes' => null, 'old' => null];
        }

        if (array_key_exists('attributes', $props) || array_key_exists('old', $props)) {
            $attributes = isset($props['attributes']) && is_array($props['attributes'])
                ? $props['attributes']
                : null;
            $old = isset($props['old']) && is_array($props['old'])
                ? $props['old']
                : null;

            return ['attributes' => $attributes, 'old' => $old];
        }

        if (isset($props['attrs']) && is_array($props['attrs'])) {
            return ['attributes' => $props['attrs'], 'old' => null];
        }

        if (isset($props['diff']) && is_array($props['diff'])) {
            $attributes = [];
            $old = [];
            foreach ($props['diff'] as $field => $change) {
                if (! is_array($change)) {
                    continue;
                }
                $attributes[$field] = $change['to'] ?? null;
                $old[$field] = $change['from'] ?? null;
            }

            return [
                'attributes' => $attributes === [] ? null : $attributes,
                'old' => $old === [] ? null : $old,
            ];
        }

        return ['attributes' => null, 'old' => null];
    }

    /**
     * @param array<string, mixed> $properties
     * @param string $field
     * @param string $side
     * @return mixed
     */
    public static function fieldValue(array $properties, string $field, string $side = 'to'): mixed
    {
        $props = self::toArray($properties);

        if (isset($props['diff'][$field]) && is_array($props['diff'][$field])) {
            return $side === 'from'
                ? ($props['diff'][$field]['from'] ?? null)
                : ($props['diff'][$field]['to'] ?? null);
        }

        if (isset($props['attrs'][$field])) {
            return $props['attrs'][$field];
        }

        if ($side === 'from' && isset($props['old'][$field])) {
            return $props['old'][$field];
        }

        if (isset($props['attributes'][$field])) {
            return $props['attributes'][$field];
        }

        return null;
    }

    /**
     * @param Activity $activity
     * @return bool
     */
    public static function isDerivableDescription(Activity $activity): bool
    {
        $logName = (string) ($activity->log_name ?? '');
        $event = (string) ($activity->event ?? '');
        $description = trim((string) $activity->description);

        if ($logName === '' || $description === '' || ! in_array($event, self::CRUD_EVENTS, true)) {
            return false;
        }

        return $description === "activity_log.{$logName}.{$event}";
    }

    /**
     * @param Activity $activity
     * @return bool
     */
    public static function shouldClearCrudDescription(Activity $activity): bool
    {
        $logName = (string) ($activity->log_name ?? '');
        $description = trim((string) $activity->description);

        if ($logName === '' || $description === '' || in_array($logName, self::DEPRECATED_LOG_NAMES, true)) {
            return false;
        }

        $derivedKey = self::deriveDescriptionKey($activity);
        if ($derivedKey === null) {
            return false;
        }

        if ($description === $derivedKey) {
            return true;
        }

        if (str_starts_with($description, 'activity_log.')) {
            foreach (self::PROTECTED_DESCRIPTION_KEYS as $protectedKey) {
                if ($description === $protectedKey || str_starts_with($description, $protectedKey)) {
                    return false;
                }
            }

            if (str_ends_with($description, '_numbered')) {
                return false;
            }

            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    public static function deprecatedLogNames(): array
    {
        return self::DEPRECATED_LOG_NAMES;
    }

    /**
     * @param Activity $activity
     * @return string|null
     */
    public static function deriveDescriptionKey(Activity $activity): ?string
    {
        $logName = (string) ($activity->log_name ?? '');
        $event = (string) ($activity->event ?? '');

        if ($logName === '' || ! in_array($event, self::CRUD_EVENTS, true)) {
            return null;
        }

        return "activity_log.{$logName}.{$event}";
    }

    /**
     * @param Activity $activity
     * @return bool
     */
    public static function isOrderCreatedActivity(Activity $activity): bool
    {
        $description = trim((string) $activity->description);

        if ($description !== '') {
            if ($description === 'created') {
                return true;
            }

            if (in_array($description, ['activity_log.order.created', 'activity_log.order.created_numbered'], true)) {
                return true;
            }

            if (in_array($description, ['Создан заказ', 'Order created'], true)) {
                return true;
            }

            return false;
        }

        return ($activity->log_name ?? '') === 'order' && ($activity->event ?? '') === 'created';
    }
}
