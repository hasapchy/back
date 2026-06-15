<?php

namespace App\Services\Timeline;

use App\Support\ActivityLog\ActivityPropertiesNormalizer;
use App\Support\Timeline\TimelineHiddenChangeFields;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientBalance;
use App\Models\Currency;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Sale;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WhMovement;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Models\WhWriteoff;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class TimelineActivityPresenter
{
    /** @var array<int, string|null> */
    private array $productUnitCache = [];

    /** @var array<int, string|null> */
    private array $unitCache = [];

    private ?string $defaultCurrencySymbolCache = null;

    /**
     * @return array<string, mixed>|null
     */
    public function processOrderTransactionActivityLog(Activity $log): ?array
    {
        if (! $log->subject instanceof Transaction) {
            return null;
        }

        $transaction = $log->subject;
        $transaction->loadMissing(['currency:id,code']);
        $currencySymbol = optional($transaction->currency)->code;
        [$descriptionKey, $descriptionParams, $descriptionFallback] = $this->buildActivityLogI18n($log);

        return [
            'type' => 'log',
            'id' => $log->id,
            'event' => $log->event,
            'description' => $descriptionFallback ?? $descriptionKey,
            'description_key' => $descriptionKey,
            'description_params' => $descriptionParams,
            'description_fallback' => $descriptionFallback,
            'changes' => null,
            'user' => $this->getUserForActivity($log, Order::class),
            'created_at' => $log->created_at,
            'log_name' => $log->log_name ?? 'transaction',
            'meta' => [
                'transaction_id' => $transaction->id,
                'amount' => $transaction->amount !== null ? (float) $transaction->amount : null,
                'currency_symbol' => $currencySymbol,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function processActivityLog(Activity $log, string $modelClass): array
    {
        $user = $this->getUserForActivity($log, $modelClass);

        $meta = null;
        $logName = $log->log_name ?? null;
        $orderCurrencySymbol = null;

        if ($log->subject && get_class($log->subject) === Order::class) {
            $log->subject->loadMissing(['cash.currency']);
            $orderCurrencySymbol = optional(optional($log->subject->cash)->currency)->code;
        }

        try {
            if ($log->subject && get_class($log->subject) === Transaction::class) {
                $txnId = $log->subject->id ?? null;
                $amount = $log->subject->amount ?? null;
                $currencySymbol = null;
                try {
                    if (isset($log->subject->currency) && isset($log->subject->currency->code)) {
                        $currencySymbol = $log->subject->currency->code;
                    } elseif (isset($log->subject->currency_id) && $log->subject->currency_id) {
                        $currencySymbol = optional(Currency::query()->select(['id', 'code'])->find($log->subject->currency_id))->code;
                    }
                } catch (\Throwable $e) {
                }

                $meta = [
                    'transaction_id' => $txnId,
                    'amount' => $amount !== null ? (float) $amount : null,
                    'currency_symbol' => $currencySymbol ?? null,
                ];
            }

            if (in_array($logName, ['order_product', 'order_temp_product'], true)) {
                $propsArray = ActivityPropertiesNormalizer::toArray($log->properties);
                $expanded = ActivityPropertiesNormalizer::expand($log->properties);
                $attrs = $expanded['attributes'] ?? [];

                if ($attrs !== []) {
                    $q = isset($attrs['quantity']) ? (float) $attrs['quantity'] : null;
                    $p = isset($attrs['price']) ? (float) $attrs['price'] : null;
                    $meta = array_merge($meta ?? [], [
                        'product_quantity' => $q,
                        'product_price' => $p,
                    ]);
                }

                $currencySymbol = $orderCurrencySymbol ?? $this->getDefaultCurrencySymbol();
                if ($currencySymbol) {
                    $meta = array_merge($meta ?? [], [
                        'product_currency_symbol' => $currencySymbol,
                    ]);
                }

                $unitName = null;
                if ($logName === 'order_product') {
                    $productIdValue = ActivityPropertiesNormalizer::fieldValue($propsArray, 'product_id', 'to')
                        ?? ActivityPropertiesNormalizer::fieldValue($propsArray, 'product_id', 'from');
                    $productId = $productIdValue !== null ? (int) $productIdValue : null;
                    $unitName = $this->getProductUnitName($productId);
                } else {
                    $unitIdValue = ActivityPropertiesNormalizer::fieldValue($propsArray, 'unit_id', 'to')
                        ?? ActivityPropertiesNormalizer::fieldValue($propsArray, 'unit_id', 'from');
                    $unitName = $this->getUnitName($unitIdValue !== null ? (int) $unitIdValue : null);
                }

                if ($unitName) {
                    $meta = array_merge($meta ?? [], [
                        'product_unit' => $unitName,
                    ]);
                }
            }
        } catch (\Throwable $e) {
        }

        [$descriptionKey, $descriptionParams, $descriptionFallback] = $this->buildActivityLogI18n($log);

        $baseRow = [
            'type' => 'log',
            'id' => $log->id,
            'event' => $log->event,
            'description' => $descriptionFallback ?? $descriptionKey,
            'description_key' => $descriptionKey,
            'description_params' => $descriptionParams,
            'description_fallback' => $descriptionFallback,
            'user' => $user,
            'created_at' => $log->created_at,
            'meta' => $meta,
            'log_name' => $logName,
        ];

        $baseRow['changes'] = ($log->event ?? '') === 'deleted'
            ? null
            : $this->processActivityChanges($log->properties, $modelClass);

        return $baseRow;
    }

    /**
     * @return array{0: ?string, 1: array<string, mixed>, 2: ?string}
     */
    private function buildActivityLogI18n(Activity $log): array
    {
        $raw = trim((string) $log->description);
        if ($raw === '') {
            $derived = ActivityPropertiesNormalizer::deriveDescriptionKey($log);
            if ($derived === null) {
                return [null, [], null];
            }
            $key = $derived;
            $params = $this->extractDescriptionParams($log, $key);
            $this->applyOrderNumberedKey($log, $key, $params);

            return [$key, $params, null];
        }
        if (! str_starts_with($raw, 'activity_log.')) {
            return [null, [], $raw];
        }
        $key = $raw;
        $params = $this->extractDescriptionParams($log, $key);
        if (str_ends_with($key, '.default')) {
            $params['event'] = (string) ($log->event ?? '');
        }
        $this->applyOrderNumberedKey($log, $key, $params);

        return [$key, $params, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityPropertyAttributes(Activity $log): array
    {
        $expanded = ActivityPropertiesNormalizer::expand($log->properties);

        return $expanded['attributes'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityPropertyOld(Activity $log): array
    {
        $expanded = ActivityPropertiesNormalizer::expand($log->properties);

        return $expanded['old'] ?? [];
    }

    /**
     * @return array<string, string>
     */
    private function extractDescriptionParams(Activity $log, string $key): array
    {
        if (str_ends_with($key, '.products_updated')) {
            $props = $log->properties;
            $all = is_object($props) && method_exists($props, 'toArray')
                ? $props->toArray()
                : (is_array($props) ? $props : []);

            return [
                'added' => implode(', ', array_map('strval', $all['added'] ?? [])),
                'removed' => implode(', ', array_map('strval', $all['removed'] ?? [])),
                'updated' => implode(', ', array_map('strval', $all['updated'] ?? [])),
            ];
        }

        if ($key === 'activity_log.order.products_updated') {
            $props = $log->properties;
            $all = is_object($props) && method_exists($props, 'toArray')
                ? $props->toArray()
                : (is_array($props) ? $props : []);

            return [
                'added' => implode(', ', array_map('strval', $all['added'] ?? [])),
                'removed' => implode(', ', array_map('strval', $all['removed'] ?? [])),
                'updated' => implode(', ', array_map('strval', $all['updated'] ?? [])),
            ];
        }

        if ($key === 'activity_log.inventory.items_counted') {
            $all = ActivityPropertiesNormalizer::toArray($log->properties);

            return [
                'counted' => (string) ($all['counted'] ?? 0),
                'with_discrepancy' => (string) ($all['with_discrepancy'] ?? 0),
            ];
        }

        if (str_starts_with($key, 'activity_log.invoice_product.')) {
            $propsArray = ActivityPropertiesNormalizer::toArray($log->properties);
            $name = ActivityPropertiesNormalizer::fieldValue($propsArray, 'product_name', 'to')
                ?? ActivityPropertiesNormalizer::fieldValue($propsArray, 'product_name', 'from');

            return [
                'name' => (string) ($name ?? ''),
            ];
        }
        if (str_starts_with($key, 'activity_log.order_temp_product.')) {
            $propsArray = ActivityPropertiesNormalizer::toArray($log->properties);
            $name = ActivityPropertiesNormalizer::fieldValue($propsArray, 'name', 'to')
                ?? ActivityPropertiesNormalizer::fieldValue($propsArray, 'name', 'from');

            return [
                'name' => (string) ($name ?? ''),
            ];
        }
        if (str_starts_with($key, 'activity_log.order_product.')) {
            $propsArray = ActivityPropertiesNormalizer::toArray($log->properties);
            $pidValue = ActivityPropertiesNormalizer::fieldValue($propsArray, 'product_id', 'to')
                ?? ActivityPropertiesNormalizer::fieldValue($propsArray, 'product_id', 'from');
            $pid = $pidValue !== null ? (int) $pidValue : 0;
            if ($pid > 0) {
                $name = Product::query()->whereKey($pid)->value('name');

                return ['name' => $name !== null ? (string) $name : ''];
            }

            return ['name' => ''];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function applyOrderNumberedKey(Activity $log, string &$key, array &$params): void
    {
        if (($log->log_name ?? '') !== 'order') {
            return;
        }

        $orderId = (int) ($log->subject_id ?? 0);
        if ($orderId < 1) {
            $subject = $log->relationLoaded('subject') ? $log->getRelation('subject') : $log->subject;
            if ($subject instanceof Order) {
                $orderId = (int) ($subject->getKey() ?? 0);
            }
        }

        if ($orderId < 1) {
            return;
        }
        foreach (['created', 'updated', 'deleted'] as $ev) {
            if ($key === "activity_log.order.{$ev}") {
                $key = "activity_log.order.{$ev}_numbered";
                $params['id'] = $orderId;

                return;
            }
        }
    }

    /**
     * @param  mixed  $changes
     * @return array<string, mixed>|null
     */
    private function processActivityChanges($changes, string $modelClass): ?array
    {
        if (! $changes) {
            return null;
        }

        $expanded = ActivityPropertiesNormalizer::expand($changes);
        $attributes = $expanded['attributes'];
        $old = $expanded['old'];

        if (! is_array($attributes)) {
            return null;
        }

        $processedAttrs = [];
        $processedOld = [];

        foreach ($attributes as $key => $value) {
            if (TimelineHiddenChangeFields::shouldSkip((string) $key)) {
                continue;
            }
            if (in_array($key, ['product_id', 'unit_id'], true)) {
                continue;
            }
            if ($this->shouldResolveActivityRelationKey((string) $key, $modelClass) && $value) {
                $relatedModel = $this->getRelatedModelName((string) $key, $modelClass);

                if ($relatedModel && class_exists($relatedModel)) {
                    try {
                        if ($relatedModel === Client::class) {
                            $relatedRecord = Client::query()->select(['id', 'first_name', 'last_name'])->find((int) $value);
                            $relatedName = $relatedRecord instanceof Client
                                ? ($relatedRecord->first_name . ' ' . $relatedRecord->last_name)
                                : $value;

                            $oldRelatedRecord = $old && isset($old[$key])
                                ? Client::query()->select(['id', 'first_name', 'last_name'])->find((int) $old[$key])
                                : null;
                            $oldRelatedName = $oldRelatedRecord instanceof Client
                                ? ($oldRelatedRecord->first_name . ' ' . $oldRelatedRecord->last_name)
                                : ($old[$key] ?? null);
                        } elseif ($relatedModel === Currency::class && $modelClass === Transaction::class && $key === 'currency_id') {
                            $relatedName = $this->resolveCurrencySymbolForTimeline($value);
                            $oldRelatedName = $old && array_key_exists($key, $old ?? [])
                                ? $this->resolveCurrencySymbolForTimeline($old[$key])
                                : null;
                        } elseif ($relatedModel === Order::class && $key === 'order_id') {
                            $relatedName = $value ? '#'.(int) $value : null;
                            $oldRelatedName = $old && isset($old[$key]) && $old[$key]
                                ? '#'.(int) $old[$key]
                                : ($old[$key] ?? null);
                        } elseif ($relatedModel === WhPurchase::class && $key === 'purchase_id') {
                            $relatedName = $value ? '#'.(int) $value : null;
                            $oldRelatedName = $old && isset($old[$key]) && $old[$key]
                                ? '#'.(int) $old[$key]
                                : ($old[$key] ?? null);
                        } elseif ($relatedModel === WhReceipt::class && $key === 'source_receipt_id') {
                            $relatedName = $value ? '#'.(int) $value : null;
                            $oldRelatedName = $old && isset($old[$key]) && $old[$key]
                                ? '#'.(int) $old[$key]
                                : ($old[$key] ?? null);
                        } elseif ($relatedModel === ClientBalance::class) {
                            $relatedName = $value ? '#'.(int) $value : null;
                            $oldRelatedName = $old && isset($old[$key]) && $old[$key]
                                ? '#'.(int) $old[$key]
                                : ($old[$key] ?? null);
                        } else {
                            $relatedName = $relatedModel::query()->select(['id', 'name'])->find($value)?->name ?? $value;
                            $oldRelatedName = $old && isset($old[$key]) ? ($relatedModel::query()->select(['id', 'name'])->find($old[$key])?->name ?? $old[$key]) : null;
                        }

                        $processedAttrs[$key] = $relatedName;
                        $processedOld[$key] = $oldRelatedName;
                    } catch (\Exception $e) {
                        $processedAttrs[$key] = $value;
                        $processedOld[$key] = $old[$key] ?? null;
                    }
                } else {
                    $processedAttrs[$key] = $value;
                    $processedOld[$key] = $old[$key] ?? null;
                }
            } else {
                $processedAttrs[$key] = $value;
                $processedOld[$key] = $old[$key] ?? null;
            }
        }

        if (! empty($processedAttrs)) {
            return [
                'attributes' => $processedAttrs,
                'old' => $processedOld,
            ];
        }

        return null;
    }

    private function getProductUnitName(?int $productId): ?string
    {
        if (! $productId) {
            return null;
        }

        if (! array_key_exists($productId, $this->productUnitCache)) {
            $product = Product::query()
                ->select(['id', 'unit_id'])
                ->with(['unit:id,name,short_name'])
                ->find($productId);

            $this->productUnitCache[$productId] = $product && $product->unit
                ? ($product->unit->short_name ?? $product->unit->name)
                : null;
        }

        return $this->productUnitCache[$productId];
    }

    private function getUnitName(?int $unitId): ?string
    {
        if (! $unitId) {
            return null;
        }

        if (! array_key_exists($unitId, $this->unitCache)) {
            $unit = Unit::query()->select(['id', 'name', 'short_name'])->find($unitId);
            $this->unitCache[$unitId] = $unit ? ($unit->short_name ?? $unit->name) : null;
        }

        return $this->unitCache[$unitId];
    }

    private function getDefaultCurrencySymbol(): ?string
    {
        if ($this->defaultCurrencySymbolCache === null) {
            $this->defaultCurrencySymbolCache = Currency::query()->where('is_default', true)->value('code');
        }

        return $this->defaultCurrencySymbolCache;
    }

    /**
     * @param  mixed  $currencyId
     * @return mixed
     */
    private function resolveCurrencySymbolForTimeline($currencyId)
    {
        if ($currencyId === null || $currencyId === '' || ! is_numeric($currencyId)) {
            return null;
        }

        return Currency::query()->select(['id', 'code'])->find((int) $currencyId)?->code ?? $currencyId;
    }

    private function getRelatedModelName(string $key, string $modelClass): ?string
    {
        $baseFieldToModelMap = [
            'client_id' => Client::class,
            'supplier_id' => Client::class,
            'client_balance_id' => ClientBalance::class,
            'creator_id' => User::class,
            'supervisor_id' => User::class,
            'executor_id' => User::class,
            'product_id' => Product::class,
            'warehouse_id' => Warehouse::class,
            'project_id' => Project::class,
            'cash_register_id' => CashRegister::class,
            'cash_id' => CashRegister::class,
            'currency_id' => Currency::class,
        ];

        $specificFieldToModelMap = [
            Order::class => [
                'category_id' => Category::class,
                'status_id' => OrderStatus::class,
            ],
            Transaction::class => [
                'category_id' => TransactionCategory::class,
            ],
            Sale::class => [
                'category_id' => Category::class,
            ],
            Task::class => [
                'status_id' => TaskStatus::class,
            ],
            Project::class => [
                'status_id' => ProjectStatus::class,
            ],
            Lead::class => [
                'status_id' => LeadStatus::class,
                'lead_source_id' => LeadSource::class,
                'responsible_id' => User::class,
                'order_id' => Order::class,
            ],
            WhReceipt::class => [
                'purchase_id' => WhPurchase::class,
            ],
            WhWriteoff::class => [
                'source_receipt_id' => WhReceipt::class,
            ],
            WhMovement::class => [
                'wh_from' => Warehouse::class,
                'wh_to' => Warehouse::class,
            ],
        ];

        if (isset($specificFieldToModelMap[$modelClass][$key])) {
            return $specificFieldToModelMap[$modelClass][$key];
        }

        return $baseFieldToModelMap[$key] ?? null;
    }

    /**
     * @return bool
     */
    private function shouldResolveActivityRelationKey(string $key, string $modelClass): bool
    {
        if (str_ends_with($key, '_id')) {
            return true;
        }

        return $modelClass === WhMovement::class && ($key === 'wh_from' || $key === 'wh_to');
    }

    /**
     * @return array{id: int, name: string, surname: string, photo: string|null}|null
     */
    private function getUserForActivity(Activity $log, string $modelClass): ?array
    {
        if ($log->causer instanceof User) {
            return TimelineUserFormatter::toArray($log->causer);
        }

        if ($log->causer_id) {
            try {
                $user = User::query()
                    ->select(explode(',', TimelineUserFormatter::SELECT_COLUMNS))
                    ->find($log->causer_id);
                if ($user instanceof User) {
                    return TimelineUserFormatter::toArray($user);
                }
            } catch (\Exception $e) {
            }
        }

        if (ActivityPropertiesNormalizer::isOrderCreatedActivity($log)) {
            try {
                $subject = $log->subject;
                if ($subject instanceof Model) {
                    $creatorId = $subject->getAttribute('creator_id');
                    if ($creatorId) {
                        $user = User::query()
                            ->select(explode(',', TimelineUserFormatter::SELECT_COLUMNS))
                            ->find($creatorId);
                        if ($user instanceof User) {
                            return TimelineUserFormatter::toArray($user);
                        }
                    }
                }
            } catch (\Exception $e) {
            }
        }

        return null;
    }
}
