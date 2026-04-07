<?php

namespace App\Services\Timeline;

use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Client;
use App\Models\Currency;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class TimelineActivityPresenter
{
    /** @var array<int, string|null> */
    private array $productUnitCache = [];

    /** @var array<int, string|null> */
    private array $unitCache = [];

    private ?string $defaultCurrencySymbolCache = null;

    /**
     * @return Collection<int, mixed>
     */
    public function collectOrderTransactionTimelineRows(int $orderId): Collection
    {
        $rows = Transaction::query()
            ->select(['id', 'source_id', 'source_type', 'amount', 'currency_id'])
            ->where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->with(['currency:id,symbol'])
            ->get()
            ->flatMap(function (Transaction $transaction) {
                return $transaction->activities()
                    ->select([
                        'activity_log.id',
                        'activity_log.description',
                        'activity_log.properties',
                        'activity_log.causer_id',
                        'activity_log.created_at',
                        'activity_log.log_name',
                        'activity_log.event',
                    ])
                    ->with(['causer:id,name', 'subject'])
                    ->get()
                    ->map(function (Activity $log) use ($transaction) {
                        $currencySymbol = optional($transaction->currency)->symbol;
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
                    });
            })
            ->filter()
            ->values();

        return new Collection($rows->all());
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
            $orderCurrencySymbol = optional(optional($log->subject->cash)->currency)->symbol;
        }

        try {
            if ($log->subject && get_class($log->subject) === Transaction::class) {
                $txnId = $log->subject->id ?? null;
                $amount = $log->subject->amount ?? null;
                $currencySymbol = null;
                try {
                    if (isset($log->subject->currency) && isset($log->subject->currency->symbol)) {
                        $currencySymbol = $log->subject->currency->symbol;
                    } elseif (isset($log->subject->currency_id) && $log->subject->currency_id) {
                        $currencySymbol = optional(Currency::query()->select(['id', 'symbol'])->find($log->subject->currency_id))->symbol;
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
                $props = $log->properties;
                $attrs = null;
                $old = null;
                if (method_exists($props, 'toArray')) {
                    $arr = $props->toArray();
                    $attrs = $arr['attributes'] ?? null;
                    $old = $arr['old'] ?? null;
                } elseif (is_object($props)) {
                    $attrs = $props->attributes ?? null;
                    $old = $props->old ?? null;
                } elseif (is_array($props)) {
                    $attrs = $props['attributes'] ?? null;
                    $old = $props['old'] ?? null;
                }
                if (is_array($attrs)) {
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
                    $productId = isset($attrs['product_id']) ? (int) $attrs['product_id'] : (isset($old['product_id']) ? (int) $old['product_id'] : null);
                    $unitName = $this->getProductUnitName($productId);
                } else {
                    $unitId = $attrs['unit_id'] ?? $old['unit_id'] ?? null;
                    $unitName = $this->getUnitName($unitId !== null ? (int) $unitId : null);
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

        if ($this->isOrderCreatedActivityDescription($log->description)) {
            $baseRow['changes'] = null;

            return $baseRow;
        }

        $baseRow['changes'] = $this->processActivityChanges($log->properties, $modelClass);

        return $baseRow;
    }

    /**
     * @return array{0: ?string, 1: array<string, mixed>, 2: ?string}
     */
    private function buildActivityLogI18n(Activity $log): array
    {
        $raw = trim((string) $log->description);
        if ($raw === '') {
            return [null, [], null];
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
        $props = $log->properties;
        $attributes = null;
        if (is_object($props) && method_exists($props, 'toArray')) {
            $attributes = ($props->toArray())['attributes'] ?? null;
        } elseif (is_object($props)) {
            $attributes = $props->attributes ?? null;
        } elseif (is_array($props)) {
            $attributes = $props['attributes'] ?? null;
        }

        return is_array($attributes) ? $attributes : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function activityPropertyOld(Activity $log): array
    {
        $props = $log->properties;
        $old = null;
        if (is_object($props) && method_exists($props, 'toArray')) {
            $old = ($props->toArray())['old'] ?? null;
        } elseif (is_object($props)) {
            $old = $props->old ?? null;
        } elseif (is_array($props)) {
            $old = $props['old'] ?? null;
        }

        return is_array($old) ? $old : [];
    }

    /**
     * @return array<string, string>
     */
    private function extractDescriptionParams(Activity $log, string $key): array
    {
        if (str_starts_with($key, 'activity_log.invoice_product.')) {
            $attrs = $this->activityPropertyAttributes($log);
            $old = $this->activityPropertyOld($log);

            return [
                'name' => (string) ($attrs['product_name'] ?? $old['product_name'] ?? ''),
            ];
        }
        if (str_starts_with($key, 'activity_log.order_temp_product.')) {
            $attrs = $this->activityPropertyAttributes($log);
            $old = $this->activityPropertyOld($log);

            return [
                'name' => (string) ($attrs['name'] ?? $old['name'] ?? ''),
            ];
        }
        if (str_starts_with($key, 'activity_log.order_product.')) {
            $attrs = $this->activityPropertyAttributes($log);
            $old = $this->activityPropertyOld($log);
            $pid = isset($attrs['product_id']) ? (int) $attrs['product_id'] : (isset($old['product_id']) ? (int) $old['product_id'] : 0);
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
        if (($log->log_name ?? '') !== 'order' || ! $log->subject instanceof Order) {
            return;
        }
        $orderId = $log->subject->getKey();
        if (! $orderId) {
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

        $attributes = null;
        $old = null;

        if (is_object($changes) && method_exists($changes, 'toArray')) {
            $changesArray = $changes->toArray();
            $attributes = $changesArray['attributes'] ?? null;
            $old = $changesArray['old'] ?? null;
        } elseif (is_object($changes)) {
            $attributes = $changes->attributes ?? null;
            $old = $changes->old ?? null;
        } elseif (is_array($changes)) {
            $attributes = $changes['attributes'] ?? null;
            $old = $changes['old'] ?? null;
        }

        if (! is_array($attributes)) {
            return null;
        }

        $processedAttrs = [];
        $processedOld = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['product_id', 'unit_id'], true)) {
                continue;
            }
            if (str_ends_with((string) $key, '_id') && $value) {
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
            $this->defaultCurrencySymbolCache = Currency::query()->where('is_default', true)->value('symbol');
        }

        return $this->defaultCurrencySymbolCache;
    }

    /**
     * @param string|null $description
     * @return bool
     */
    private function isOrderCreatedActivityDescription(?string $description): bool
    {
        if ($description === null || $description === '') {
            return false;
        }

        $trimmed = trim($description);

        if ($trimmed === 'created') {
            return true;
        }

        if (in_array($trimmed, ['activity_log.order.created', 'activity_log.order.created_numbered'], true)) {
            return true;
        }

        if (in_array($trimmed, ['Создан заказ', 'Order created'], true)) {
            return true;
        }

        return false;
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

        return Currency::query()->select(['id', 'symbol'])->find((int) $currencyId)?->symbol ?? $currencyId;
    }

    private function getRelatedModelName(string $key, string $modelClass): ?string
    {
        $baseFieldToModelMap = [
            'client_id' => Client::class,
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
        ];

        if (isset($specificFieldToModelMap[$modelClass][$key])) {
            return $specificFieldToModelMap[$modelClass][$key];
        }

        return $baseFieldToModelMap[$key] ?? null;
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function getUserForActivity(Activity $log, string $modelClass): ?array
    {
        if ($log->causer instanceof User) {
            return [
                'id' => $log->causer->id,
                'name' => $log->causer->name,
            ];
        }

        if ($log->causer_id) {
            try {
                $user = User::query()->select(['id', 'name'])->find($log->causer_id);
                if ($user instanceof User) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                    ];
                }
            } catch (\Exception $e) {
            }
        }

        if ($this->isOrderCreatedActivityDescription($log->description)) {
            try {
                $subject = $log->subject;
                if ($subject instanceof Model) {
                    $creatorId = $subject->getAttribute('creator_id');
                    if ($creatorId) {
                        $user = User::query()->select(['id', 'name'])->find($creatorId);
                        if ($user instanceof User) {
                            return [
                                'id' => $user->id,
                                'name' => $user->name,
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
            }
        }

        return null;
    }
}
