<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Transaction;
use App\Services\CacheService;
use App\Services\CurrencyConverter;
use App\Services\RoundingService;
use App\Services\TransactionSourceService;
use App\Services\ClientBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionsRepository extends BaseRepository
{
    /**
     * ID категории транзакций для корректировок расходов
     */
    private const ADJUSTMENT_EXPENSE_CATEGORY_ID = 21;

    /**
     * ID категории транзакций для корректировок приходов
     */
    private const ADJUSTMENT_INCOME_CATEGORY_ID = 22;

    /**
     * Получить транзакции с пагинацией
     *
     * @param  int  $userUuid  ID пользователя
     * @param  int  $perPage  Количество записей на страницу
     * @param  int  $page  Номер страницы
     * @param  int|null  $cash_id  ID кассы
     * @param  string|null  $date_filter_type  Тип фильтра по дате
     * @param  int|null  $order_id  ID заказа
     * @param  string|null  $search  Поисковый запрос
     * @param  int|null  $transaction_type  Тип транзакции (0 - расход, 1 - доход)
     * @param  string|null  $source  Источник транзакции
     * @param  int|null  $project_id  ID проекта
     * @param  string|null  $start_date  Начальная дата
     * @param  string|null  $end_date  Конечная дата
     * @param  bool|null  $is_debt  Фильтр по долгу (true - долги, false - платежи)
     * @param  array|null  $category_ids  Массив ID категорий транзакций
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 10, $page = 1, $cash_id = null, $date_filter_type = null, $order_id = null, $search = null, $transaction_type = null, $source = null, $project_id = null, $start_date = null, $end_date = null, $is_debt = null, $category_ids = null)
    {
        $companyId = $this->getCurrentCompanyId() ?? 'default';

        $showDeleted = false;
        if ($companyId && $companyId !== 'default') {
            $company = Company::findOrFail($companyId);
            $showDeleted = $company->show_deleted_transactions ?? false;
        }

        /** @var \App\Models\User|null $currentUser */
        $currentUser = auth('api')->user();
        $searchKey = $search !== null ? md5(trim((string) $search)) : 'null';
        $showDeletedKey = $showDeleted ? '1' : '0';
        $sourcePermissionsKey = $this->getSourcePermissionsKey($currentUser);
        $categoryIdsKey = $category_ids ? md5(implode(',', $category_ids)) : 'null';
        $cacheKey = $this->generateCacheKey('transactions_paginated', [$perPage, $cash_id, $date_filter_type, $order_id, $searchKey, $transaction_type, $source, $project_id, $start_date, $end_date, $is_debt, $categoryIdsKey, $showDeletedKey, $sourcePermissionsKey, $currentUser?->id, $this->getCurrentCompanyId()]);

        return CacheService::getPaginatedData($cacheKey, function () use ($perPage, $page, $cash_id, $date_filter_type, $order_id, $search, $transaction_type, $source, $project_id, $start_date, $end_date, $is_debt, $category_ids, $showDeleted, $currentUser) {
            $searchTrimmed = is_string($search) ? trim($search) : '';
            $hasSearch = $searchTrimmed !== '' && mb_strlen($searchTrimmed) >= 3;

            if ($hasSearch) {
                Log::info('transactions.search.start', [
                    'user_id' => $currentUser?->id,
                    'company_id' => $this->getCurrentCompanyId(),
                    'search' => $searchTrimmed,
                    'search_length' => mb_strlen($searchTrimmed),
                ]);
            }
            $query = Transaction::with([
                'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                'client.phones:id,client_id,phone',
                'client.emails:id,client_id,email',
                'currency:id,name,symbol',
                'cashRegister:id,name,currency_id',
                'cashRegister.currency:id,name,symbol',
                'category:id,name,type',
                'project:id,name',
                'user:id,name',
                'cashTransfersFrom:id,tr_id_from',
                'cashTransfersTo:id,tr_id_to',
            ])
                ->addSelect([
                    'client_balance' => DB::table('clients')
                        ->select('balance')
                        ->whereColumn('clients.id', 'transactions.client_id')
                        ->limit(1),
                ]);

            $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister');

            if (! $showDeleted) {
                $query->where('transactions.is_deleted', false);
            }

            $query->when($cash_id, function ($query, $cash_id) {
                return $query->where('transactions.cash_id', $cash_id);
            })
                ->when($date_filter_type && $date_filter_type !== 'all_time', function ($query) use ($date_filter_type, $start_date, $end_date) {
                    if ($date_filter_type === 'custom') {
                        if ($start_date && $end_date) {
                            return $this->applyDateFilter($query, 'custom', $start_date, $end_date, 'transactions.date');
                        } elseif ($start_date) {
                            return $query->where('transactions.date', '>=', \Carbon\Carbon::parse($start_date)->startOfDay()->toDateTimeString());
                        } elseif ($end_date) {
                            return $query->where('transactions.date', '<=', \Carbon\Carbon::parse($end_date)->endOfDay()->toDateTimeString());
                        }

                        return $query;
                    }

                    return $this->applyDateFilter($query, $date_filter_type, $start_date, $end_date, 'transactions.date');
                })
                ->when($order_id, function ($query, $order_id) {
                    return $query->where('source_type', 'App\\Models\\Order')
                        ->where('source_id', $order_id);
                })
                ->when($hasSearch, function ($query) use ($searchTrimmed) {
                    $searchLower = mb_strtolower($searchTrimmed);

                    return $query->where(function ($q) use ($searchTrimmed, $searchLower) {
                        $q->where('transactions.id', 'like', "%{$searchTrimmed}%")
                            ->orWhereRaw('LOWER(transactions.note) LIKE ?', ["%{$searchLower}%"]);

                        $q->orWhereExists(function ($subQuery) use ($searchTrimmed) {
                            $subQuery->select(DB::raw(1))
                                ->from('clients')
                                ->whereColumn('clients.id', 'transactions.client_id')
                                ->where(function ($clientQuery) use ($searchTrimmed) {
                                    $this->applyClientSearchConditions($clientQuery, $searchTrimmed);
                                });
                        })
                            ->orWhereExists(function ($phoneSubQuery) use ($searchLower) {
                                $phoneSubQuery->select(DB::raw(1))
                                    ->from('clients_phones')
                                    ->whereColumn('clients_phones.client_id', 'transactions.client_id')
                                    ->whereRaw('LOWER(phone) LIKE ?', ["%{$searchLower}%"]);
                            })
                            ->orWhereExists(function ($emailSubQuery) use ($searchLower) {
                                $emailSubQuery->select(DB::raw(1))
                                    ->from('clients_emails')
                                    ->whereColumn('clients_emails.client_id', 'transactions.client_id')
                                    ->whereRaw('LOWER(email) LIKE ?', ["%{$searchLower}%"]);
                            });
                    });
                })
                ->when($transaction_type, function ($query, $transaction_type) {
                    switch ($transaction_type) {
                        case 'income':
                            return $query->where('transactions.type', 1);
                        case 'outcome':
                            return $query->where('transactions.type', 0);
                        case 'transfer':
                            return $query->where(function ($q) {
                                $q->whereExists(function ($subQuery) {
                                    $subQuery->select(DB::raw(1))
                                        ->from('cash_transfers')
                                        ->whereColumn('cash_transfers.tr_id_from', 'transactions.id');
                                })->orWhereExists(function ($subQuery) {
                                    $subQuery->select(DB::raw(1))
                                        ->from('cash_transfers')
                                        ->whereColumn('cash_transfers.tr_id_to', 'transactions.id');
                                });
                            });
                        default:
                            return $query;
                    }
                })
                ->when($source, function ($q, $source) {
                    if (empty($source)) {
                        return $q;
                    }

                    return $q->where(function ($subQ) use ($source) {
                        if ($source === 'sale') {
                            $subQ->where('source_type', 'App\\Models\\Sale');
                        } elseif ($source === 'order') {
                            $subQ->where('source_type', 'App\\Models\\Order');
                        } elseif ($source === 'receipt') {
                            $subQ->where('source_type', 'App\\Models\\WhReceipt');
                        } elseif ($source === 'salary') {
                            $subQ->where('source_type', 'App\\Models\\EmployeeSalary');
                        } elseif ($source === 'other') {
                            $subQ->whereNull('source_type')
                                ->orWhereNotIn('source_type', ['App\\Models\\Sale', 'App\\Models\\Order', 'App\\Models\\WhReceipt', 'App\\Models\\EmployeeSalary']);
                        }
                    });
                })
                ->when($project_id, function ($q, $project_id) {
                    return $q->where('transactions.project_id', $project_id);
                })
                ->when($is_debt !== null, function ($q) use ($is_debt) {
                    $isDebtBool = filter_var($is_debt, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                    if ($isDebtBool !== null) {
                        return $q->where('transactions.is_debt', $isDebtBool);
                    }

                    return $q;
                })
                ->when($category_ids && is_array($category_ids) && count($category_ids) > 0, function ($q) use ($category_ids) {
                    return $q->whereIn('transactions.category_id', $category_ids);
                });

            $this->applyOwnFilter($query, 'transactions', 'transactions', 'user_id', $currentUser);
            $this->applySourceTypeFilter($query, $currentUser);

            $query->orderBy('transactions.id', 'desc');

            if ($hasSearch) {
                Log::info('transactions.search.query', [
                    'sql' => $query->toSql(),
                    'bindings' => $query->getBindings(),
                ]);
            }

            /** @var \Illuminate\Pagination\LengthAwarePaginator $paginatedResults */
            $paginatedResults = $query->paginate($perPage, ['*'], 'page', (int) $page);
            Log::info('transactions.search.result', [
                'user_id' => $currentUser?->id,
                'company_id' => $this->getCurrentCompanyId(),
                'search' => $searchTrimmed,
                'has_search' => $hasSearch,
                'cash_id' => $cash_id,
                'date_filter_type' => $date_filter_type,
                'order_id' => $order_id,
                'transaction_type' => $transaction_type,
                'source' => $source,
                'project_id' => $project_id,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'is_debt' => $is_debt,
                'category_ids' => $category_ids,
                'show_deleted' => $showDeleted,
                'count' => $paginatedResults->total(),
            ]);

            $paginatedResults->getCollection()->load([
                'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                'client.phones:id,client_id,phone',
                'client.emails:id,client_id,email',
                'currency:id,name,symbol',
                'cashRegister:id,name,currency_id',
                'cashRegister.currency:id,name,symbol',
                'category:id,name,type',
                'project:id,name',
                'user:id,name',
                'cashTransfersFrom:id,tr_id_from',
                'cashTransfersTo:id,tr_id_to',
            ]);

            $debtStats = DB::table('clients')
                ->select([
                    DB::raw('SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END) as positive'),
                    DB::raw('SUM(CASE WHEN balance < 0 THEN ABS(balance) ELSE 0 END) as negative'),
                ])
                ->first();

            $totalDebtPositive = $debtStats->positive ?? 0;
            $totalDebtNegative = $debtStats->negative ?? 0;

            $paginatedResults->getCollection()->transform(function ($transaction) {
                return (object) [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'is_transfer' => $this->isTransfer($transaction),
                    'is_sale' => $this->isSale($transaction),
                    'is_receipt' => $this->isReceipt($transaction),
                    'is_debt' => $transaction->is_debt,
                    'cash_id' => $transaction->cash_id,
                    'cash_name' => $transaction->cashRegister?->name,
                    'cash_amount' => $transaction->amount,
                    'cash_currency_id' => $transaction->cashRegister?->currency?->id,
                    'cash_currency_name' => $transaction->cashRegister?->currency?->name,
                    'cash_currency_symbol' => $transaction->cashRegister?->currency?->symbol,
                    'orig_amount' => $transaction->orig_amount,
                    'orig_currency_id' => $transaction->currency?->id,
                    'orig_currency_name' => $transaction->currency?->name,
                    'orig_currency_symbol' => $transaction->currency?->symbol,
                    'exchange_rate' => $transaction->exchange_rate,
                    'user_id' => $transaction->user_id,
                    'user_name' => $transaction->user?->name,
                    'category_id' => $transaction->category_id,
                    'category_name' => $transaction->category?->name,
                    'category_type' => $transaction->category?->type,
                    'project_id' => $transaction->project_id,
                    'project_name' => $transaction->project?->name,
                    'client_id' => $transaction->client_id,
                    'client' => $transaction->client ? [
                        'id' => $transaction->client->id,
                        'first_name' => $transaction->client->first_name,
                        'last_name' => $transaction->client->last_name,
                        'contact_person' => $transaction->client->contact_person,
                        'client_type' => $transaction->client->client_type,
                        'is_supplier' => $transaction->client->is_supplier,
                        'is_conflict' => $transaction->client->is_conflict,
                        'address' => $transaction->client->address,
                        'note' => $transaction->client->note,
                        'status' => $transaction->client->status,
                        'discount_type' => $transaction->client->discount_type,
                        'discount' => $transaction->client->discount,
                        'created_at' => $transaction->client->created_at,
                        'updated_at' => $transaction->client->updated_at,
                        'phones' => $transaction->client->phones ? $transaction->client->phones->toArray() : [],
                        'emails' => $transaction->client->emails ? $transaction->client->emails->toArray() : [],
                        'balance' => $transaction->client_balance ?? 0,
                    ] : null,
                    'note' => $transaction->note,
                    'date' => $transaction->date,
                    'created_at' => $transaction->created_at,
                    'updated_at' => $transaction->updated_at,
                    'source_type' => $transaction->source_type,
                    'source_id' => $transaction->source_id,
                    'is_deleted' => $transaction->is_deleted ?? false,
                ];
            });

            /** @var object $paginatedResults */
            $paginatedResults->total_debt_positive = $totalDebtPositive;
            $paginatedResults->total_debt_negative = $totalDebtNegative;
            $paginatedResults->total_debt_balance = $totalDebtPositive - $totalDebtNegative;

            return $paginatedResults;
        }, (int) $page);
    }

    /**
     * Создать транзакцию
     *
     * @param  array  $data  Данные транзакции
     * @param  bool  $return_id  Вернуть ID транзакции вместо true
     * @param  bool  $skipClientUpdate  Пропустить обновление баланса клиента
     * @return int|bool ID транзакции или true
     *
     * @throws \Exception
     */
    public function createItem($data, $return_id = false, bool $skipClientUpdate = false)
    {
        $cashRegister = CashRegister::findOrFail($data['cash_id']);
        $originalAmount = $data['orig_amount'];
        $companyId = $this->getCurrentCompanyId();
        $defaultCurrency = Currency::where('is_default', true)
            ->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->first();
        $defaultCurrencyId = $defaultCurrency->id;

        $currencyIds = array_unique([
            $data['currency_id'],
            $cashRegister->currency_id,
            $defaultCurrencyId,
        ]);

        $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');

        $fromCurrency = $currencies[$data['currency_id']];
        $toCurrency = $currencies[$cashRegister->currency_id];
        $defaultCurrency = $currencies[$defaultCurrencyId];

        $roundingService = new RoundingService;
        $transactionDate = $data['date'] ?? now();

        $reportCurrency = Currency::where('is_report', true)
            ->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->first();

        if (! empty($data['exchange_rate']) && $data['exchange_rate'] > 0) {
            $exchangeRate = (float) $data['exchange_rate'];
            $convertedAmount = $originalAmount * $exchangeRate;
        } else {
            if ($fromCurrency->id === $toCurrency->id) {
                $exchangeRate = 1.0;
                $convertedAmount = $originalAmount;
            } else {
                $fromRate = $fromCurrency->getExchangeRateForCompany($companyId, $transactionDate);
                $toRate = $toCurrency->getExchangeRateForCompany($companyId, $transactionDate);

                if ($fromCurrency->id === $defaultCurrency->id) {
                    $exchangeRate = 1 / $toRate;
                } elseif ($toCurrency->id === $defaultCurrency->id) {
                    $exchangeRate = $fromRate;
                } else {
                    $exchangeRate = $fromRate / $toRate;
                }

                $convertedAmount = CurrencyConverter::convert($originalAmount, $fromCurrency, $toCurrency, $defaultCurrency, $companyId, $transactionDate);
            }
        }

        $convertedAmount = $roundingService->roundForCompany($companyId, (float) $convertedAmount);

        if ($fromCurrency->id !== $defaultCurrency->id) {
            $convertedAmountDefault = CurrencyConverter::convert($originalAmount, $fromCurrency, $defaultCurrency, null, $companyId, $transactionDate);
        } else {
            $convertedAmountDefault = $originalAmount;
        }

        $roundedConvertedAmountDefault = $roundingService->roundForCompany($companyId, (float) $convertedAmountDefault);

        if (! $reportCurrency) {
            throw new \Exception('Валюта отчетов не найдена для компании');
        }

        $repAmount = CurrencyConverter::convert($originalAmount, $fromCurrency, $reportCurrency, $defaultCurrency, $companyId, $transactionDate);
        $repAmount = $roundingService->roundForCompany($companyId, $repAmount);

        if ($fromCurrency->id === $reportCurrency->id) {
            $repRate = 1.0;
        } else {
            $fromRate = $fromCurrency->getExchangeRateForCompany($companyId, $transactionDate);
            $reportRate = $reportCurrency->getExchangeRateForCompany($companyId, $transactionDate);
            if ($fromCurrency->id === $defaultCurrency->id) {
                $repRate = 1 / $reportRate;
            } elseif ($reportCurrency->id === $defaultCurrency->id) {
                $repRate = $fromRate;
            } else {
                $repRate = $fromRate / $reportRate;
            }
        }

        if ($fromCurrency->id === $defaultCurrency->id) {
            $defRate = 1.0;
            $defAmount = $originalAmount;
        } else {
            $defRate = $fromCurrency->getExchangeRateForCompany($companyId, $transactionDate);
            $defAmount = $roundedConvertedAmountDefault;
        }

        $skipForOrderProject = (($data['source_type'] ?? null) === \App\Models\Order::class) && ! empty($data['project_id']);

        if ($skipForOrderProject) {
            $companyIdForCheck = $this->getCurrentCompanyId();
            $company = $companyIdForCheck ? Company::find($companyIdForCheck) : null;

            if ($company) {
                $skipForOrderProject = (bool) $company->skip_project_order_balance;
            }
        }

        DB::beginTransaction();

        try {
            $transaction = new Transaction;
            $transaction->type = $data['type'];
            $transaction->user_id = $data['user_id'];
            $transaction->orig_amount = $originalAmount;
            $transaction->amount = $convertedAmount;
            $transaction->currency_id = $data['currency_id'];
            $transaction->cash_id = $cashRegister->id;

            if (isset($data['is_adjustment']) && $data['is_adjustment']) {
                $adjustmentCategoryId = $data['type'] == 1
                    ? self::ADJUSTMENT_INCOME_CATEGORY_ID
                    : self::ADJUSTMENT_EXPENSE_CATEGORY_ID;
                $transaction->category_id = $adjustmentCategoryId;
            } else {
                $transaction->category_id = $data['category_id'];
            }

            $transaction->project_id = $data['project_id'];
            $transaction->client_id = $data['client_id'];
            $transaction->note = $data['note'] ?? null;
            $transaction->date = $data['date'];
            $transaction->is_debt = $data['is_debt'] ?? false;
            $transaction->source_type = $data['source_type'] ?? null;
            $transaction->source_id = $data['source_id'] ?? null;
            $transaction->exchange_rate = $exchangeRate;
            $transaction->rep_rate = $repRate;
            $transaction->rep_amount = $repAmount;
            $transaction->def_rate = $defRate;
            $transaction->def_amount = $defAmount;

            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->setSkipCashBalanceUpdate(true);
            $transaction->save();

            if (! $skipClientUpdate && ! empty($data['client_id']) && ! $skipForOrderProject) {
                $client = Client::find($data['client_id']);
                if ($client) {
                    $balanceId = null;

                    Log::info('Transaction client balance update', [
                        'transaction_id' => $transaction->id ?? 'new',
                        'client_id' => $data['client_id'],
                        'client_balance_id' => $data['client_balance_id'] ?? null,
                        'has_client_balance_id' => !empty($data['client_balance_id']),
                        'original_amount' => $originalAmount,
                        'currency_id' => $fromCurrency->id,
                        'type' => $data['type'],
                        'is_debt' => $data['is_debt'] ?? false,
                    ]);

                    if (!empty($data['client_balance_id'])) {
                        $balanceId = (int) $data['client_balance_id'];
                        $clientBalance = \App\Models\ClientBalance::lockForUpdate()->find($balanceId);

                        Log::info('Using specific client balance', [
                            'balance_id' => $balanceId,
                            'balance_found' => $clientBalance !== null,
                            'balance_client_id' => $clientBalance->client_id ?? null,
                            'request_client_id' => $client->id,
                            'balance_currency_id' => $clientBalance->currency_id ?? null,
                            'transaction_currency_id' => $fromCurrency->id,
                        ]);

                        if ($clientBalance && $clientBalance->client_id == $client->id) {
                            $balanceCurrency = $clientBalance->currency;
                            if ($balanceCurrency) {
                                $delta = 0;

                                if ($balanceCurrency->id === $fromCurrency->id) {
                                    $amountToUse = $originalAmount;
                                    Log::info('Balance currency matches transaction currency, no conversion needed');
                                } else {
                                    $defaultCurrency = Currency::where('is_default', true)->first();
                                    if ($defaultCurrency) {
                                        $convertedAmount = CurrencyConverter::convert(
                                            $originalAmount,
                                            $fromCurrency,
                                            $balanceCurrency,
                                            $defaultCurrency,
                                            $companyId,
                                            $transactionDate,
                                            $data['exchange_rate'] ?? null,
                                            $toCurrency
                                        );
                                        $roundingService = new \App\Services\RoundingService;
                                        $amountToUse = $roundingService->roundForCompany($companyId, $convertedAmount);
                                        Log::info('Converted amount for balance', [
                                            'original_amount' => $originalAmount,
                                            'converted_amount' => $amountToUse,
                                            'from_currency' => $fromCurrency->id,
                                            'to_currency' => $balanceCurrency->id,
                                        ]);
                                    } else {
                                        $amountToUse = $originalAmount;
                                        Log::warning('Default currency not found, using original amount');
                                    }
                                }

                                $sign = (bool) ($data['is_debt'] ?? false)
                                    ? ($data['type'] == 1 ? 1 : -1)
                                    : ($data['type'] == 1 ? -1 : 1);

                                $delta = $sign * $amountToUse;
                                $oldBalance = $clientBalance->balance;
                                $clientBalance->increment('balance', $delta);

                                Log::info('Updated specific client balance', [
                                    'balance_id' => $balanceId,
                                    'old_balance' => $oldBalance,
                                    'new_balance' => $clientBalance->fresh()->balance,
                                    'delta' => $delta,
                                    'amount_used' => $amountToUse,
                                    'sign' => $sign,
                                ]);
                            }
                        } else {
                            Log::warning('Client balance not found or client mismatch', [
                                'balance_id' => $balanceId,
                                'balance_client_id' => $clientBalance->client_id ?? null,
                                'request_client_id' => $client->id,
                            ]);
                        }
                    } else {
                        Log::info('No client_balance_id provided, using default balance logic');
                        $balanceId = ClientBalanceService::updateBalance(
                            $client,
                            $fromCurrency,
                            $originalAmount,
                            $data['type'],
                            (bool) ($data['is_debt'] ?? false),
                            $companyId,
                            $transactionDate,
                            $data['exchange_rate'] ?? null,
                            $toCurrency
                        );

                        Log::info('Updated balance using default logic', [
                            'balance_id' => $balanceId,
                        ]);
                    }

                    if ($balanceId) {
                        $transaction->client_balance_id = $balanceId;
                        $transaction->save();

                        Log::info('Transaction client_balance_id set', [
                            'transaction_id' => $transaction->id,
                            'client_balance_id' => $balanceId,
                        ]);
                    } else {
                        Log::warning('No balance_id returned from updateBalance', [
                            'client_id' => $data['client_id'],
                        ]);
                    }

                    CacheService::invalidateClientsCache();
                    $this->invalidateClientBalanceCache($data['client_id']);
                    CacheService::invalidateProjectsCache();
                }
            }

            if (! $transaction->source_type && ! $transaction->source_id) {
                TransactionSourceService::setSalarySource($transaction);
                if ($transaction->source_type || $transaction->source_id) {
                    $transaction->save();
                }
            }

            if (! ($data['is_debt'] ?? false) && $cashRegister) {
                if ((int) $data['type'] === 1) {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance + ' . ($convertedAmount + 0)),
                    ]);
                } else {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance - ' . ($convertedAmount + 0)),
                    ]);
                }
            }

            DB::commit();

            $this->invalidateTransactionsCache();
            if (! empty($data['client_id'])) {
                $this->invalidateClientBalanceCache($data['client_id']);
            }

            CacheService::invalidateCashRegistersCache();

            if (! empty($data['project_id'])) {
                $projectsRepository = new \App\Repositories\ProjectsRepository;
                $projectsRepository->invalidateProjectCache($data['project_id']);
            }

            if (($data['source_type'] ?? null) === 'App\Models\Order' && ! empty($data['source_id'])) {
                CacheService::invalidateOrdersCache();
            }
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $return_id ? $transaction->id : true;
    }

    /**
     * Обновить транзакцию
     *
     * @param  int  $id  ID транзакции
     * @param  array  $data  Данные для обновления
     * @return bool
     *
     * @throws \Exception
     */
    public function updateItem($id, $data)
    {
        $transaction = Transaction::findOrFail($id);

        $transaction->setSkipClientBalanceUpdate(true);
        $transaction->setSkipCashBalanceUpdate(true);

        $needsBalanceUpdate = isset($data['orig_amount']) || isset($data['currency_id']) || isset($data['is_debt']) || isset($data['client_id']);

        if ($needsBalanceUpdate) {
            return $this->updateItemWithBalanceRecalculation($id, $data);
        }

        $transaction->client_id = $data['client_id'];
        $transaction->category_id = $data['category_id'];
        $transaction->project_id = $data['project_id'];
        $transaction->date = $data['date'];
        $transaction->note = ! empty($data['note']) ? $data['note'] : null;

        if (isset($data['is_debt'])) {
            $transaction->is_debt = $data['is_debt'];
        }

        if (array_key_exists('source_type', $data)) {
            $transaction->source_type = $data['source_type'];
        }
        if (array_key_exists('source_id', $data)) {
            $transaction->source_id = $data['source_id'];
        }
        if (! empty($data['exchange_rate']) && $data['exchange_rate'] > 0) {
            $transaction->exchange_rate = (float) $data['exchange_rate'];
        }

        $transaction->save();

        if (! $transaction->source_type && ! $transaction->source_id) {
            TransactionSourceService::setSalarySource($transaction);
            if ($transaction->source_type || $transaction->source_id) {
                $transaction->save();
            }
        }

        $this->invalidateTransactionsCache();
        if ($transaction->client_id) {
            $this->invalidateClientBalanceCache($transaction->client_id);
        }

        CacheService::invalidateCashRegistersCache();

        if ($transaction->project_id) {
            $projectsRepository = new ProjectsRepository;
            $projectsRepository->invalidateProjectCache($transaction->project_id);
        }

        if ($transaction->source_type === 'App\Models\Order' && $transaction->source_id) {
            CacheService::invalidateOrdersCache();
        }

        return true;
    }

    /**
     * Обновить транзакцию с пересчетом баланса
     *
     * @param  int  $id  ID транзакции
     * @param  array  $data  Данные для обновления
     * @return bool
     *
     * @throws \Exception
     */
    private function updateItemWithBalanceRecalculation($id, $data)
    {
        $transaction = Transaction::findOrFail($id);

        DB::beginTransaction();

        try {
            $cashRegister = CashRegister::findOrFail($transaction->cash_id);

            $oldAmount = $transaction->amount;
            $oldOrigAmount = $transaction->orig_amount;
            $oldCurrencyId = $transaction->currency_id;
            $oldIsDebt = $transaction->is_debt;
            $oldClientId = $transaction->client_id;
            $oldSourceType = $transaction->source_type;
            $oldType = $transaction->type;
            $oldProjectId = $transaction->project_id;
            $oldExchangeRate = $transaction->exchange_rate;

            if (! $oldIsDebt) {
                if ($oldType == 1) {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance - ' . ($oldAmount + 0)),
                    ]);
                } else {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance + ' . ($oldAmount + 0)),
                    ]);
                }
            }

            $transaction->client_id = $data['client_id'];
            $transaction->category_id = $data['category_id'];
            $transaction->project_id = $data['project_id'];
            $transaction->date = $data['date'];
            $transaction->note = $data['note'] ?? null;

            if (array_key_exists('source_type', $data)) {
                $transaction->source_type = $data['source_type'];
            }
            if (array_key_exists('source_id', $data)) {
                $transaction->source_id = $data['source_id'];
            }

            if (isset($data['orig_amount'])) {
                $transaction->orig_amount = $data['orig_amount'];
            }
            if (isset($data['currency_id'])) {
                $transaction->currency_id = $data['currency_id'];
            }
            if (isset($data['is_debt'])) {
                $transaction->is_debt = $data['is_debt'];
            }

            $newOrigAmount = $transaction->orig_amount;
            $newCurrencyId = $transaction->currency_id;

            $roundingService = new RoundingService;
            $companyId = $this->getCurrentCompanyId();
            $transactionDate = $transaction->date ?? now();

            $defaultCurrency = Currency::where('is_default', true)
                ->where(function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)->orWhereNull('company_id');
                })
                ->first();
            $defaultCurrencyId = $defaultCurrency->id;
            $currencyIds = array_unique([
                $newCurrencyId,
                $cashRegister->currency_id,
                $defaultCurrencyId,
            ]);

            $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');
            $fromCurrency = $currencies[$newCurrencyId];
            $toCurrency = $currencies[$cashRegister->currency_id];
            $defaultCurrency = $currencies[$defaultCurrencyId];

            $reportCurrency = Currency::where('is_report', true)
                ->where(function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)->orWhereNull('company_id');
                })
                ->first();

            if (! empty($data['exchange_rate']) && $data['exchange_rate'] > 0) {
                $exchangeRate = (float) $data['exchange_rate'];
                $newConvertedAmount = $newOrigAmount * $exchangeRate;
            } else {
                if ($fromCurrency->id === $toCurrency->id) {
                    $exchangeRate = 1.0;
                    $newConvertedAmount = $newOrigAmount;
                } else {
                    $fromRate = $fromCurrency->getExchangeRateForCompany($companyId, $transactionDate);
                    $toRate = $toCurrency->getExchangeRateForCompany($companyId, $transactionDate);

                    if ($fromCurrency->id === $defaultCurrency->id) {
                        $exchangeRate = 1 / $toRate;
                    } elseif ($toCurrency->id === $defaultCurrency->id) {
                        $exchangeRate = $fromRate;
                    } else {
                        $exchangeRate = $fromRate / $toRate;
                    }

                    $newConvertedAmount = CurrencyConverter::convert($newOrigAmount, $fromCurrency, $toCurrency, $defaultCurrency, $companyId, $transactionDate);
                }
            }

            $newConvertedAmount = $roundingService->roundForCompany($companyId, (float) $newConvertedAmount);

            if ($fromCurrency->id !== $defaultCurrency->id) {
                $newConvertedAmountDefault = CurrencyConverter::convert($newOrigAmount, $fromCurrency, $defaultCurrency, null, $companyId, $transactionDate);
            } else {
                $newConvertedAmountDefault = $newOrigAmount;
            }

            $roundedNewConvertedAmountDefault = $roundingService->roundForCompany($companyId, (float) $newConvertedAmountDefault);

            if (! $reportCurrency) {
                throw new \Exception('Валюта отчетов не найдена для компании');
            }

            $repAmount = CurrencyConverter::convert($newOrigAmount, $fromCurrency, $reportCurrency, $defaultCurrency, $companyId, $transactionDate);
            $repAmount = $roundingService->roundForCompany($companyId, $repAmount);

            if ($fromCurrency->id === $reportCurrency->id) {
                $repRate = 1.0;
            } else {
                $fromRate = $fromCurrency->getExchangeRateForCompany($companyId, $transactionDate);
                $reportRate = $reportCurrency->getExchangeRateForCompany($companyId, $transactionDate);
                if ($fromCurrency->id === $defaultCurrency->id) {
                    $repRate = 1 / $reportRate;
                } elseif ($reportCurrency->id === $defaultCurrency->id) {
                    $repRate = $fromRate;
                } else {
                    $repRate = $fromRate / $reportRate;
                }
            }

            if ($fromCurrency->id === $defaultCurrency->id) {
                $defRate = 1.0;
                $defAmount = $newOrigAmount;
            } else {
                $defRate = $fromCurrency->getExchangeRateForCompany($companyId, $transactionDate);
                $defAmount = $roundedNewConvertedAmountDefault;
            }

            $transaction->amount = $newConvertedAmount;
            $transaction->exchange_rate = $exchangeRate;
            $transaction->rep_rate = $repRate;
            $transaction->rep_amount = $repAmount;
            $transaction->def_rate = $defRate;
            $transaction->def_amount = $defAmount;

            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->setSkipCashBalanceUpdate(true);

            $transaction->save();

            if (! $transaction->source_type && ! $transaction->source_id) {
                TransactionSourceService::setSalarySource($transaction);
                if ($transaction->source_type || $transaction->source_id) {
                    $transaction->save();
                }
            }

            if (! $transaction->is_debt) {
                if ($transaction->type == 1) {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance + ' . ($newConvertedAmount + 0)),
                    ]);
                } else {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance - ' . ($newConvertedAmount + 0)),
                    ]);
                }
            }

            $companyId = $this->getCurrentCompanyId();
            $company = $companyId ? Company::find($companyId) : null;
            $skipProjectOrderBalance = $company ? (bool) $company->skip_project_order_balance : true;

            $isOrder = ($transaction->source_type === \App\Models\Order::class);
            $hadProject = ! empty($oldProjectId);
            $hasProject = ! empty($transaction->project_id);

            $shouldSkipOld = $isOrder && $hadProject && $skipProjectOrderBalance;
            $shouldSkipNew = $isOrder && $hasProject && $skipProjectOrderBalance;

            if (! $shouldSkipOld && $oldClientId) {
                $oldClient = Client::find($oldClientId);
                $oldCurrency = $currencies[$oldCurrencyId];
                $oldCashCurrency = $cashRegister->currency;

                if ($oldClient && $oldCurrency) {
                    Log::info('transaction.client_balance.on_update_old', [
                        'transaction_id' => $transaction->id,
                        'client_id' => $oldClientId,
                        'orig_amount' => $oldOrigAmount,
                        'currency_id' => $oldCurrencyId,
                        'cash_currency_id' => $oldCashCurrency->id,
                        'company_id' => $companyId,
                        'exchange_rate' => $oldExchangeRate ?? null,
                    ]);

                    ClientBalanceService::updateBalance(
                        $oldClient,
                        $oldCurrency,
                        -$oldOrigAmount,
                        $oldType,
                        $oldIsDebt,
                        $companyId,
                        $transaction->date,
                        $oldExchangeRate ?? null,
                        $oldCashCurrency
                    );
                }
            }

            if (! $shouldSkipNew && $transaction->client_id) {
                $client = Client::find($transaction->client_id);
                if ($client) {
                    Log::info('transaction.client_balance.on_update_new', [
                        'transaction_id' => $transaction->id,
                        'client_id' => $transaction->client_id,
                        'orig_amount' => $newOrigAmount,
                        'currency_id' => $fromCurrency->id,
                        'cash_currency_id' => $toCurrency->id,
                        'company_id' => $companyId,
                        'exchange_rate' => $data['exchange_rate'] ?? null,
                    ]);

                    ClientBalanceService::updateBalance(
                        $client,
                        $fromCurrency,
                        $newOrigAmount,
                        $transaction->type,
                        (bool) $transaction->is_debt,
                        $companyId,
                        $transaction->date,
                        $data['exchange_rate'] ?? null,
                        $toCurrency
                    );
                }
            }

            if ($oldClientId || $transaction->client_id) {
                CacheService::invalidateClientsCache();
                if ($oldClientId) {
                    $this->invalidateClientBalanceCache($oldClientId);
                }
                if ($transaction->client_id && $transaction->client_id !== $oldClientId) {
                    $this->invalidateClientBalanceCache($transaction->client_id);
                }
                CacheService::invalidateProjectsCache();
            }

            DB::commit();

            $this->invalidateTransactionsCache();
            if ($transaction->client_id) {
                $this->invalidateClientBalanceCache($transaction->client_id);
            }

            CacheService::invalidateCashRegistersCache();

            if ($transaction->project_id) {
                $projectsRepository = new ProjectsRepository;
                $projectsRepository->invalidateProjectCache($transaction->project_id);
            }

            if ($transaction->source_type === 'App\Models\Order' && $transaction->source_id) {
                CacheService::invalidateOrdersCache();
            }

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Удалить транзакцию
     *
     * @param  int  $id  ID транзакции
     * @param  bool  $skipClientUpdate  Пропустить обновление баланса клиента
     *
     * @throws \Exception
     */
    public function deleteItem(int $id, bool $skipClientUpdate = false): bool
    {
        $transaction = Transaction::findOrFail($id);

        DB::beginTransaction();

        try {
            $cashRegister = CashRegister::findOrFail($transaction->cash_id);

            $defaultCurrencyId = Currency::where('is_default', true)->value('id');
            $currencyIds = array_unique([
                $transaction->currency_id,
                $defaultCurrencyId,
            ]);

            $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');
            $fromCurrency = $currencies[$transaction->currency_id];
            $defaultCurrency = $currencies[$defaultCurrencyId];

            $roundingService = new RoundingService;
            $companyId = $this->getCurrentCompanyId();
            $transactionDate = $transaction->date ?? now();

            $convertedAmount = $transaction->amount;

            if (! $transaction->is_debt) {
                if ($transaction->type == 1) {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance - ' . ($convertedAmount + 0)),
                    ]);
                } else {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance + ' . ($convertedAmount + 0)),
                    ]);
                }
            }

            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->setSkipCashBalanceUpdate(true);

            DB::table('transactions')->where('id', $transaction->id)->update(['is_deleted' => true]);

            $skipForOrderProject = ($transaction->source_type === \App\Models\Order::class) && ! empty($transaction->project_id);
            if ($skipForOrderProject) {
                $company = $companyId ? Company::findOrFail($companyId) : null;
                $skipForOrderProject = $company ? (bool) $company->skip_project_order_balance : $skipForOrderProject;
            }
            if (! $skipClientUpdate && $transaction->client_id && ! $skipForOrderProject) {
                $client = Client::find($transaction->client_id);
                $cashCurrency = $cashRegister->currency;

                if ($client) {
                    if ($transaction->client_balance_id) {
                        $clientBalance = \App\Models\ClientBalance::lockForUpdate()->find($transaction->client_balance_id);
                        if ($clientBalance && $clientBalance->client_id == $client->id) {
                            $balanceCurrency = $clientBalance->currency;
                            $amountToUse = $transaction->orig_amount;

                            if ($balanceCurrency->id !== $fromCurrency->id) {
                                $defaultCurrency = Currency::where('is_default', true)->first();
                                $convertedAmount = CurrencyConverter::convert(
                                    $transaction->orig_amount,
                                    $fromCurrency,
                                    $balanceCurrency,
                                    $defaultCurrency,
                                    $companyId,
                                    $transactionDate,
                                    $transaction->exchange_rate,
                                    $cashCurrency
                                );
                                $roundingService = new RoundingService;
                                $amountToUse = $roundingService->roundForCompany($companyId, $convertedAmount);
                            }

                            $sign = (bool) $transaction->is_debt
                                ? ($transaction->type == 1 ? -1 : 1)
                                : ($transaction->type == 1 ? 1 : -1);

                            $clientBalance->increment('balance', $sign * $amountToUse);
                        }
                    } else {
                        ClientBalanceService::updateBalance(
                            $client,
                            $fromCurrency,
                            -$transaction->orig_amount,
                            $transaction->type,
                            (bool) $transaction->is_debt,
                            $companyId,
                            $transactionDate,
                            $transaction->exchange_rate,
                            $cashCurrency
                        );
                    }

                    CacheService::invalidateClientsCache();
                    CacheService::invalidateClientBalanceCache($transaction->client_id);
                    CacheService::invalidateProjectsCache();
                }
            }

            DB::commit();

            $this->invalidateTransactionsCache();
            if ($transaction->client_id) {
                $this->invalidateClientBalanceCache($transaction->client_id);
            }

            CacheService::invalidateCashRegistersCache();

            if ($transaction->project_id) {
                $projectsRepository = new ProjectsRepository;
                $projectsRepository->invalidateProjectCache($transaction->project_id);
            }

            if ($transaction->source_type === 'App\Models\Order' && $transaction->source_id) {
                CacheService::invalidateOrdersCache();
            }

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Получить общую сумму транзакций по заказу
     *
     * @param  int  $userId  ID пользователя
     * @param  int  $orderId  ID заказа
     * @return float
     */
    public function getTotalByOrderId($userId, $orderId)
    {
        $total = Transaction::where('source_type', 'App\Models\Order')
            ->where('source_id', $orderId)
            ->where('is_debt', 0)
            ->where('is_deleted', false)
            ->sum('orig_amount');

        return $total;
    }

    /**
     * Проверить, является ли транзакция перемещением между кассами
     *
     * @param  Transaction  $transaction  Транзакция
     * @return bool
     */
    private function isTransfer($transaction)
    {
        if ($transaction->relationLoaded('cashTransfersFrom') && $transaction->relationLoaded('cashTransfersTo')) {
            return $transaction->cashTransfersFrom->count() > 0 || $transaction->cashTransfersTo->count() > 0;
        }

        return $transaction->cashTransfersFrom()->exists() || $transaction->cashTransfersTo()->exists();
    }

    /**
     * Проверить, является ли транзакция продажей
     *
     * @param  Transaction  $transaction  Транзакция
     * @return bool
     */
    private function isSale($transaction)
    {
        return $transaction->source_type === 'App\Models\Sale';
    }

    /**
     * Проверить, является ли транзакция оприходованием
     *
     * @param  Transaction  $transaction  Транзакция
     * @return bool
     */
    private function isReceipt($transaction)
    {
        return $transaction->source_type === 'App\Models\WhReceipt';
    }

    /**
     * Инвалидировать кэш транзакций
     *
     * @return void
     */
    public function invalidateTransactionsCache()
    {
        CacheService::invalidateTransactionsCache();
    }

    /**
     * Получить транзакцию по ID
     *
     * @param  int  $id  ID транзакции
     * @return \Illuminate\Support\Collection|null
     */
    public function getItemById($id)
    {
        $items = $this->getItems([$id]);

        return $items->first();
    }

    /**
     * Получить транзакции по массиву ID
     *
     * @param  array  $ids  Массив ID транзакций
     * @return \Illuminate\Support\Collection
     */
    private function getItems(array $ids = [])
    {
        $query = Transaction::query();
        $query->leftJoin('users as users', 'transactions.user_id', '=', 'users.id');
        $query->leftJoin('currencies as currencies', 'transactions.currency_id', '=', 'currencies.id');
        $query->join('cash_registers as cash_registers', 'transactions.cash_id', '=', 'cash_registers.id');
        $query->join('currencies as cash_register_currencies', 'cash_registers.currency_id', '=', 'cash_register_currencies.id');
        $query->leftJoin('transaction_categories as transaction_categories', 'transactions.category_id', '=', 'transaction_categories.id');
        $query->leftJoin('projects as projects', 'transactions.project_id', '=', 'projects.id');
        $query->leftJoin('cash_transfers as cash_transfers_from', 'transactions.id', '=', 'cash_transfers_from.tr_id_from');
        $query->leftJoin('cash_transfers as cash_transfers_to', 'transactions.id', '=', 'cash_transfers_to.tr_id_to');

        $query->whereIn('transactions.id', $ids);
        $query->select(
            'transactions.id as id',
            'transactions.type as type',
            'transactions.is_debt as is_debt',
            DB::raw('CASE
            WHEN cash_transfers_from.tr_id_from IS NOT NULL
              OR cash_transfers_to.tr_id_to IS NOT NULL
                THEN true
                ELSE false
            END as is_transfer'),
            'transactions.cash_id as cash_id',
            'cash_registers.name as cash_name',
            'transactions.amount as cash_amount',
            'cash_register_currencies.id as cash_currency_id',
            'cash_register_currencies.name as cash_currency_name',
            'cash_register_currencies.symbol as cash_currency_symbol',
            'transactions.orig_amount as orig_amount',
            'currencies.id as orig_currency_id',
            'currencies.name as orig_currency_name',
            'currencies.symbol as orig_currency_symbol',
            'transactions.exchange_rate as exchange_rate',
            'transactions.user_id as user_id',
            'users.name as user_name',
            'transactions.category_id as category_id',
            'transaction_categories.name as category_name',
            'transaction_categories.type as category_type',
            'transactions.project_id as project_id',
            'projects.name as project_name',
            'transactions.client_id as client_id',
            'transactions.note as note',
            'transactions.date as date',
            'transactions.source_type as source_type',
            'transactions.source_id as source_id',
            'transactions.updated_at as updated_at',
            'transactions.created_at as created_at',
            'transactions.is_deleted as is_deleted',
        );
        $items = $query->get();

        $clientIds = $items->pluck('client_id')->filter()->unique()->toArray();
        $clients = collect();

        if (! empty($clientIds)) {
            $clients = Client::whereIn('id', $clientIds)
                ->with(['phones', 'emails'])
                ->select([
                    'id',
                    'first_name',
                    'last_name',
                    'contact_person',
                    'client_type',
                    'is_supplier',
                    'is_conflict',
                    'address',
                    'note',
                    'status',
                    'discount_type',
                    'discount',
                    'created_at',
                    'updated_at',
                    'clients.balance as balance',
                ])
                ->get()
                ->keyBy('id');
        }

        foreach ($items as $item) {
            $item = (object) $item->toArray();
            $item->client = $clients->get($item->client_id);
        }

        return $items;
    }

    /**
     * Применить фильтр по источникам транзакций на основе пермишенов пользователя
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query  Query builder
     * @param  \App\Models\User|null  $user  Пользователь
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function applySourceTypeFilter($query, $user = null)
    {
        /** @var \App\Models\User|null $user */
        $user = $user ?? auth('api')->user();
        if (! $user) {
            return $query;
        }

        if ($user->is_admin) {
            return $query;
        }

        $permissions = $this->getUserPermissionsForCompany($user);

        // Права по источникам работают независимо от transactions_view_all
        // transactions_view_all влияет только на фильтр по user_id (через applyOwnFilter)
        $hasViewSale = in_array('transactions_view_sale', $permissions);
        $hasViewOrder = in_array('transactions_view_order', $permissions);
        $hasViewReceipt = in_array('transactions_view_receipt', $permissions);
        $hasViewSalary = in_array('transactions_view_salary', $permissions);
        $hasViewOther = in_array('transactions_view_other', $permissions);

        $hasAnySourcePermission = $hasViewSale || $hasViewOrder || $hasViewReceipt || $hasViewSalary || $hasViewOther;

        // Если есть права по источникам, применяем фильтр
        // Если нет прав по источникам, но есть базовое право transactions_view, показываем все
        // Если нет никаких прав, фильтр не применяется (для обратной совместимости)
        if (! $hasAnySourcePermission) {
            return $query;
        }

        $query->where(function ($q) use ($hasViewSale, $hasViewOrder, $hasViewReceipt, $hasViewSalary, $hasViewOther) {
            if ($hasViewSale) {
                $q->orWhere('transactions.source_type', 'App\\Models\\Sale');
            }
            if ($hasViewOrder) {
                $q->orWhere('transactions.source_type', 'App\\Models\\Order');
            }
            if ($hasViewReceipt) {
                $q->orWhere('transactions.source_type', 'App\\Models\\WhReceipt');
            }
            if ($hasViewSalary) {
                $q->orWhere('transactions.source_type', 'App\\Models\\EmployeeSalary');
            }
            if ($hasViewOther) {
                $q->orWhere(function ($subQ) {
                    $subQ->whereNull('transactions.source_type')
                        ->orWhereNotIn('transactions.source_type', [
                            'App\\Models\\Sale',
                            'App\\Models\\Order',
                            'App\\Models\\WhReceipt',
                            'App\\Models\\EmployeeSalary',
                        ]);
                });
            }
        });

        return $query;
    }

    /**
     * Получить ключ для кэша на основе пермишенов пользователя по источникам
     *
     * @param  \App\Models\User|null  $user  Пользователь
     * @return string
     */
    private function getSourcePermissionsKey($user = null)
    {
        /** @var \App\Models\User|null $user */
        $user = $user ?? auth('api')->user();
        if (! $user) {
            return 'no_user';
        }

        if ($user->is_admin) {
            return 'admin';
        }

        $permissions = $this->getUserPermissionsForCompany($user);

        // Права по источникам работают независимо от transactions_view_all
        // transactions_view_all влияет только на фильтр по user_id
        $sources = [];
        if (in_array('transactions_view_sale', $permissions)) {
            $sources[] = 'sale';
        }
        if (in_array('transactions_view_order', $permissions)) {
            $sources[] = 'order';
        }
        if (in_array('transactions_view_receipt', $permissions)) {
            $sources[] = 'receipt';
        }
        if (in_array('transactions_view_salary', $permissions)) {
            $sources[] = 'salary';
        }
        if (in_array('transactions_view_other', $permissions)) {
            $sources[] = 'other';
        }

        if (empty($sources)) {
            // Если нет прав по источникам, возвращаем 'all' для кэша
            // (это означает, что фильтр по источникам не применяется)
            return 'all';
        }
        sort($sources);

        return implode('_', $sources);
    }

    /**
     * Конвертировать сумму в валюту по умолчанию
     *
     * @param  float  $amount  Сумма для конвертации
     * @param  \App\Models\Currency  $fromCurrency  Исходная валюта
     * @param  \App\Models\Currency  $defaultCurrency  Валюта по умолчанию
     * @param  int|null  $companyId  ID компании
     * @param  string|\DateTime|null  $date  Дата для конвертации (опционально)
     * @return float Сумма в валюте по умолчанию
     */
    private function convertAmountToDefaultCurrency(float $amount, \App\Models\Currency $fromCurrency, \App\Models\Currency $defaultCurrency, ?int $companyId, $date = null, ?float $exchangeRate = null, ?\App\Models\Currency $cashCurrency = null): float
    {
        // Если передан ручной курс и известна валюта кассы, используем его для конвертации
        if ($exchangeRate !== null && $exchangeRate > 0 && $cashCurrency) {
            // Сумма в валюте кассы по ручному курсу
            $amountInCashCurrency = $amount * $exchangeRate;

            if ($cashCurrency->id === $defaultCurrency->id) {
                // Если валюта кассы = валюта компании, просто возвращаем сумму в валюте кассы
                $amount = $amountInCashCurrency;
            } else {
                // Переводим сумму кассы в валюту компании по курсу кассы из истории
                $amount = CurrencyConverter::convert($amountInCashCurrency, $cashCurrency, $defaultCurrency, null, $companyId, $date ?? now());
            }
        } elseif ($fromCurrency->id !== $defaultCurrency->id) {
            // Старое поведение: конвертация напрямую из валюты транзакции в валюту компании
            $amount = CurrencyConverter::convert($amount, $fromCurrency, $defaultCurrency, null, $companyId, $date ?? now());
        }

        $roundingService = new RoundingService;

        return $roundingService->roundForCompany($companyId, (float) $amount);
    }
}
