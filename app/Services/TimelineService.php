<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\Currency;
use App\Models\Client;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Category;
use App\Models\OrderStatus;
use App\Models\TransactionCategory;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Models\Project;
use App\Models\CashRegister;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class TimelineService
{
    /**
     * @var RoundingService
     */
    protected $roundingService;

    /**
     * @var array
     */
    protected $productUnitCache = [];

    /**
     * @var array
     */
    protected $unitCache = [];

    /**
     * @var string|null
     */
    protected $defaultCurrencySymbolCache = null;

    /**
     * @param RoundingService $roundingService
     */
    public function __construct(RoundingService $roundingService)
    {
        $this->roundingService = $roundingService;
    }

    /**
     * Построить таймлайн для модели
     *
     * @param string $modelClass
     * @param int $id
     * @return Collection
     * @throws \Exception
     */
    public function buildTimeline(string $modelClass, int $id): Collection
    {
        $model = $modelClass::select([
            'id', 'client_id', 'user_id', 'status_id', 'category_id'
        ])
        ->with([
            'client:id,first_name,last_name,contact_person',
            'user:id,name',
            'status:id,name',
            'category:id,name'
        ])
        ->findOrFail($id);

        if ($model->client) {
            $model->client->name = $model->client->first_name . ' ' . $model->client->last_name;
        }

        if (!method_exists($model, 'comments') || !method_exists($model, 'activities') || !method_exists($model, 'getKey')) {
            throw new \Exception('Модель не поддерживает комментарии или активность');
        }

        $comments = $this->getCommentsForModel($model);
        $activities = $this->getActivitiesForModel($model, $modelClass);

        if ($modelClass === Order::class) {
            $orderActivities = $this->getOrderSpecificActivities($id);
            $activities = $activities->merge($orderActivities);
        }

        $timeline = collect($comments)
            ->merge($activities)
            ->sortBy(function ($item) {
                return \Carbon\Carbon::parse($item['created_at']);
            })
            ->values();

        return $timeline;
    }

    /**
     * Получить комментарии для модели
     *
     * @param Model $model
     * @return Collection
     */
    public function getCommentsForModel(Model $model): Collection
    {
        return $model->comments()
            ->select([
                'comments.id', 'comments.body', 'comments.user_id', 'comments.created_at'
            ])
            ->with(['user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($comment) {
                return [
                    'type' => 'comment',
                    'id' => $comment->id,
                    'body' => $comment->body,
                    'user' => $comment->user,
                    'created_at' => $comment->created_at,
                ];
            });
    }

    /**
     * Получить активности для модели
     *
     * @param Model $model
     * @param string $modelClass
     * @return Collection
     */
    public function getActivitiesForModel(Model $model, string $modelClass): Collection
    {
        return $model->activities()
            ->select([
                'activity_log.id', 'activity_log.description', 'activity_log.properties',
                'activity_log.causer_id', 'activity_log.created_at', 'activity_log.log_name'
            ])
            ->with(['causer:id,name', 'subject'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($log) use ($modelClass) {
                return $this->processActivityLog($log, $modelClass);
            });
    }

    /**
     * Обработать лог активности
     *
     * @param Activity $log
     * @param string $modelClass
     * @return array
     */
    public function processActivityLog(Activity $log, string $modelClass): array
    {
        $user = $this->getUserForActivity($log, $modelClass);

        $description = $log->description;
        $meta = null;
        $logName = $log->log_name ?? null;
        $orderCurrencySymbol = null;

        if ($log->subject && get_class($log->subject) === Order::class) {
            $log->subject->loadMissing(['cash.currency']);
            $orderCurrencySymbol = optional(optional($log->subject->cash)->currency)->symbol;
        }

        try {
            if ($log->subject && get_class($log->subject) === Order::class && isset($log->subject->id)) {
                $description = rtrim($description) . ' #' . $log->subject->id;
            }

            if ($log->subject && get_class($log->subject) === Transaction::class) {
                $meta = $this->processTransactionActivity($log);
                $description = $this->formatTransactionDescription($log, $description);
            }

            if (in_array($logName, ['order_product', 'order_temp_product'])) {
                $meta = $this->processProductActivity($log, $orderCurrencySymbol, $meta);
            }
        } catch (\Throwable $e) {
        }

        if ($log->description === 'created' || $log->description === 'Создан заказ') {
            return [
                'type' => 'log',
                'id' => $log->id,
                'description' => $description,
                'changes' => null,
                'user' => $user,
                'created_at' => $log->created_at,
                'meta' => $meta,
                'log_name' => $logName,
            ];
        }

        $changes = $this->processActivityChanges($log->properties, $modelClass);

        return [
            'type' => 'log',
            'id' => $log->id,
            'description' => $description,
            'changes' => $changes,
            'user' => $user,
            'created_at' => $log->created_at,
            'meta' => $meta,
            'log_name' => $logName,
        ];
    }

    /**
     * Обработать активность транзакции
     *
     * @param Activity $log
     * @return array
     */
    protected function processTransactionActivity(Activity $log): array
    {
        $txnId = $log->subject->id ?? null;
        $amount = $log->subject->amount ?? null;
        $currencySymbol = null;

        try {
            if (isset($log->subject->currency) && isset($log->subject->currency->symbol)) {
                $currencySymbol = $log->subject->currency->symbol;
            } elseif (isset($log->subject->currency_id) && $log->subject->currency_id) {
                $currencySymbol = optional(Currency::select('id','symbol')->find($log->subject->currency_id))->symbol;
            }
        } catch (\Throwable $e) {
        }

        return [
            'transaction_id' => $txnId,
            'amount' => $amount !== null ? (float)$amount : null,
            'currency_symbol' => $currencySymbol,
        ];
    }

    /**
     * Форматировать описание транзакции
     *
     * @param Activity $log
     * @param string $description
     * @return string
     */
    protected function formatTransactionDescription(Activity $log, string $description): string
    {
        $txnId = $log->subject->id ?? null;
        $amount = $log->subject->amount ?? null;
        $currencySymbol = null;

        try {
            if (isset($log->subject->currency) && isset($log->subject->currency->symbol)) {
                $currencySymbol = $log->subject->currency->symbol;
            } elseif (isset($log->subject->currency_id) && $log->subject->currency_id) {
                $currencySymbol = optional(Currency::select('id','symbol')->find($log->subject->currency_id))->symbol;
            }
        } catch (\Throwable $e) {
        }

        if ($txnId !== null || $amount !== null) {
            $parts = [];
            if ($txnId !== null) {
                $parts[] = '#' . $txnId;
            }
            if ($amount !== null) {
                $formatted = $this->formatAmount(null, (float)$amount);
                $parts[] = 'сумма: ' . $formatted . ($currencySymbol ? (' ' . $currencySymbol) : '');
            }
            $description = rtrim($description) . ' (' . implode(', ', $parts) . ')';
        }

        return $description;
    }

    /**
     * Обработать активность продукта
     *
     * @param Activity $log
     * @param string|null $orderCurrencySymbol
     * @param array|null $existingMeta
     * @return array
     */
    protected function processProductActivity(Activity $log, ?string $orderCurrencySymbol, ?array $existingMeta): array
    {
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

        $meta = $existingMeta ?? [];

        if (is_array($attrs)) {
            $q = isset($attrs['quantity']) ? (float)$attrs['quantity'] : null;
            $p = isset($attrs['price']) ? (float)$attrs['price'] : null;
            $meta = array_merge($meta, [
                'product_quantity' => $q,
                'product_price' => $p,
            ]);
        }

        $currencySymbol = $orderCurrencySymbol ?? $this->getDefaultCurrencySymbol();
        if ($currencySymbol) {
            $meta['product_currency_symbol'] = $currencySymbol;
        }

        $logName = $log->log_name ?? null;
        $unitName = null;
        if ($logName === 'order_product') {
            $productId = $attrs['product_id'] ?? $old['product_id'] ?? null;
            $unitName = $this->getProductUnitName($productId);
        } else {
            $unitId = $attrs['unit_id'] ?? $old['unit_id'] ?? null;
            $unitName = $this->getUnitName($unitId);
        }

        if ($unitName) {
            $meta['product_unit'] = $unitName;
        }

        return $meta;
    }

    /**
     * Обработать изменения в активности
     *
     * @param mixed $changes
     * @param string $modelClass
     * @return array|null
     */
    public function processActivityChanges($changes, string $modelClass): ?array
    {
        if (!$changes) {
            return null;
        }

        $attributes = null;
        $old = null;

        if (method_exists($changes, 'toArray')) {
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

        if (!is_array($attributes)) {
            return null;
        }

        $processedAttrs = [];
        $processedOld = [];

        foreach ($attributes as $key => $value) {
            if (in_array($key, ['product_id', 'unit_id'], true)) {
                continue;
            }
            if (str_ends_with($key, '_id') && $value) {
                $relatedModel = $this->getRelatedModelName($key, $modelClass);

                if ($relatedModel && class_exists($relatedModel)) {
                    try {
                        if ($relatedModel === Client::class) {
                            $relatedRecord = $relatedModel::select('id,first_name,last_name,contact_person')->find($value);
                            $relatedName = $relatedRecord ? ($relatedRecord->first_name . ' ' . $relatedRecord->last_name) : $value;

                            $oldRelatedRecord = $old && isset($old[$key]) ? $relatedModel::select('id,first_name,last_name,contact_person')->find($old[$key]) : null;
                            $oldRelatedName = $oldRelatedRecord ? ($oldRelatedRecord->first_name . ' ' . $oldRelatedRecord->last_name) : ($old && isset($old[$key]) ? $old[$key] : null);
                        } else {
                            $relatedName = $relatedModel::select('id,name')->find($value)?->name ?? $value;
                            $oldRelatedName = $old && isset($old[$key]) ? ($relatedModel::select('id,name')->find($old[$key])?->name ?? $old[$key]) : null;
                        }

                        $processedAttrs[$key] = $relatedName;
                        $processedOld[$key] = $oldRelatedName;
                    } catch (\Exception $e) {
                        $processedAttrs[$key] = $value;
                        $processedOld[$key] = $old && isset($old[$key]) ? $old[$key] : null;
                    }
                } else {
                    $processedAttrs[$key] = $value;
                    $processedOld[$key] = $old && isset($old[$key]) ? $old[$key] : null;
                }
            } else {
                $processedAttrs[$key] = $value;
                $processedOld[$key] = $old && isset($old[$key]) ? $old[$key] : null;
            }
        }

        if (!empty($processedAttrs)) {
            return [
                'attributes' => $processedAttrs,
                'old' => $processedOld,
            ];
        }

        return null;
    }

    /**
     * Получить специфичные активности для заказа
     *
     * @param int $orderId
     * @return Collection
     */
    protected function getOrderSpecificActivities(int $orderId): Collection
    {
        $orderTransactionLogs = Transaction::select(['id', 'source_id', 'source_type', 'amount', 'currency_id'])
            ->where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->with(['currency:id,symbol'])
            ->get()
            ->flatMap(function ($transaction) {
                return $transaction->activities()
                    ->select(['activity_log.id', 'activity_log.description', 'activity_log.causer_id', 'activity_log.created_at'])
                    ->with(['causer:id,name', 'subject'])
                    ->get()->map(function ($log) use ($transaction) {
                        $desc = $log->description;
                        $parts = [];
                        $parts[] = '#' . $transaction->id;
                        if (!is_null($transaction->amount)) {
                            $symbol = optional($transaction->currency)->symbol;
                            $formatted = $this->formatAmount(null, (float)$transaction->amount);
                            $parts[] = 'сумма: ' . $formatted . ($symbol ? (' ' . $symbol) : '');
                        }
                        $desc = rtrim($desc) . ' (' . implode(', ', $parts) . ')';
                        return $log->setAttribute('description', $desc);
                    });
            })->map(function ($log) {
                return [
                    'type' => 'log',
                    'id' => $log->id,
                    'description' => $log->description,
                    'changes' => null,
                    'user' => $this->getUserForActivity($log, Order::class),
                    'created_at' => $log->created_at,
                ];
            })->filter();

        return $orderTransactionLogs;
    }

    /**
     * Получить имя связанной модели по ключу
     *
     * @param string $key
     * @param string $modelClass
     * @return string|null
     */
    protected function getRelatedModelName(string $key, string $modelClass): ?string
    {
        $baseFieldToModelMap = [
            'client_id' => Client::class,
            'user_id' => User::class,
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
        ];

        if (isset($specificFieldToModelMap[$modelClass]) && isset($specificFieldToModelMap[$modelClass][$key])) {
            return $specificFieldToModelMap[$modelClass][$key];
        }

        return $baseFieldToModelMap[$key] ?? null;
    }

    /**
     * Получить пользователя для активности
     *
     * @param Activity $log
     * @param string $modelClass
     * @return array|null
     */
    protected function getUserForActivity(Activity $log, string $modelClass): ?array
    {
        if ($log->causer) {
            return [
                'id' => $log->causer->id,
                'name' => $log->causer->name,
            ];
        }

        if ($log->causer_id) {
            try {
                $user = User::select('id', 'name')->find($log->causer_id);
                if ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                    ];
                }
            } catch (\Exception $e) {
            }
        }

        if ($log->description === 'created' || $log->description === 'Создан заказ') {
            try {
                $subject = $log->subject;
                if ($subject && isset($subject->user_id) && $subject->user_id) {
                    $user = User::select('id', 'name')->find($subject->user_id);
                    if ($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                        ];
                    }
                }
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    /**
     * Получить имя единицы измерения продукта
     *
     * @param int|null $productId
     * @return string|null
     */
    protected function getProductUnitName(?int $productId): ?string
    {
        if (!$productId) {
            return null;
        }

        if (!array_key_exists($productId, $this->productUnitCache)) {
            $product = Product::select(['id', 'unit_id'])
                ->with(['unit:id,name,short_name'])
                ->find($productId);

            $this->productUnitCache[$productId] = $product && $product->unit
                ? ($product->unit->short_name ?? $product->unit->name)
                : null;
        }

        return $this->productUnitCache[$productId];
    }

    /**
     * Получить имя единицы измерения
     *
     * @param int|null $unitId
     * @return string|null
     */
    protected function getUnitName(?int $unitId): ?string
    {
        if (!$unitId) {
            return null;
        }

        if (!array_key_exists($unitId, $this->unitCache)) {
            $unit = Unit::select(['id', 'name', 'short_name'])->find($unitId);
            $this->unitCache[$unitId] = $unit ? ($unit->short_name ?? $unit->name) : null;
        }

        return $this->unitCache[$unitId];
    }

    /**
     * Получить символ валюты по умолчанию
     *
     * @return string|null
     */
    protected function getDefaultCurrencySymbol(): ?string
    {
        if ($this->defaultCurrencySymbolCache === null) {
            $this->defaultCurrencySymbolCache = Currency::where('is_default', true)->value('symbol');
        }

        return $this->defaultCurrencySymbolCache;
    }

    /**
     * Форматировать сумму для компании
     *
     * @param int|null $companyId
     * @param float $amount
     * @return string
     */
    public function formatAmount(?int $companyId, float $amount): string
    {
        try {
            $decimals = $this->roundingService->getDecimalsForCompany($companyId);
            $rounded = $this->roundingService->roundForCompany($companyId, $amount);
            return number_format($rounded, $decimals, '.', ' ');
        } catch (\Throwable $e) {
            return (string)$amount;
        }
    }
}

