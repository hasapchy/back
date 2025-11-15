<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Currency;
use App\Models\Project;
use App\Models\Company;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use App\Services\CacheService;
use App\Repositories\ProjectsRepository;
use Illuminate\Support\Facades\DB;
use App\Services\RoundingService;

class TransactionsRepository extends BaseRepository
{
    /**
     * Получить транзакции с пагинацией
     *
     * @param int $userUuid ID пользователя
     * @param int $perPage Количество записей на страницу
     * @param int $page Номер страницы
     * @param int|null $cash_id ID кассы
     * @param string|null $date_filter_type Тип фильтра по дате
     * @param int|null $order_id ID заказа
     * @param string|null $search Поисковый запрос
     * @param int|null $transaction_type Тип транзакции (0 - расход, 1 - доход)
     * @param string|null $source Источник транзакции
     * @param int|null $project_id ID проекта
     * @param string|null $start_date Начальная дата
     * @param string|null $end_date Конечная дата
     * @param bool|null $is_debt Фильтр по долгу (true - долги, false - платежи)
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getItemsWithPagination($userUuid, $perPage = 10, $page = 1, $cash_id = null, $date_filter_type = null, $order_id = null, $search = null, $transaction_type = null, $source = null, $project_id = null, $start_date = null, $end_date = null, $is_debt = null)
    {
        try {
            $companyId = $this->getCurrentCompanyId() ?? 'default';

            $showDeleted = false;
            if ($companyId && $companyId !== 'default') {
                $company = Company::findOrFail($companyId);
                $showDeleted = $company->show_deleted_transactions ?? false;
            }

            /** @var \App\Models\User|null $currentUser */
            $currentUser = auth('api')->user();
            $searchKey = $search !== null ? md5(trim((string)$search)) : 'null';
            $showDeletedKey = $showDeleted ? '1' : '0';
            $cacheKey = $this->generateCacheKey('transactions_paginated', [$userUuid, $perPage, $cash_id, $date_filter_type, $order_id, $searchKey, $transaction_type, $source, $project_id, $start_date, $end_date, $is_debt, $showDeletedKey, $currentUser?->id]);

            return CacheService::getPaginatedData($cacheKey, function () use ($userUuid, $perPage, $page, $cash_id, $date_filter_type, $order_id, $search, $transaction_type, $source, $project_id, $start_date, $end_date, $is_debt, $showDeleted, $currentUser) {
                $searchNormalized = trim((string)$search);
                $query = Transaction::with([
                    'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'currency:id,name,code,symbol',
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,name,code,symbol',
                    'category:id,name,type',
                    'project:id,name',
                    'user:id,name',
                    'cashTransfersFrom:id,tr_id_from',
                    'cashTransfersTo:id,tr_id_to'
                ])
                    ->addSelect([
                        'client_balance' => DB::table('clients')
                            ->select('balance')
                            ->whereColumn('clients.id', 'transactions.client_id')
                            ->limit(1)
                    ]);

                $query = $this->addCompanyFilterThroughRelation($query, 'cashRegister');

                if (!$showDeleted) {
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
                    ->when($searchNormalized !== '', function ($query) use ($searchNormalized) {
                        return $query->where(function ($q) use ($searchNormalized) {
                            if (is_numeric($searchNormalized)) {
                                $q->where('transactions.id', $searchNormalized)
                                    ->orWhere('transactions.note', 'like', "%{$searchNormalized}%");
                            } else {
                                $q->where('transactions.note', 'like', "%{$searchNormalized}%");
                            }

                            $q->orWhereHas('client', function ($clientQuery) use ($searchNormalized) {
                                $clientQuery->where(function ($subQuery) use ($searchNormalized) {
                                    $subQuery->where('first_name', 'like', "%{$searchNormalized}%")
                                        ->orWhere('last_name', 'like', "%{$searchNormalized}%")
                                        ->orWhere('contact_person', 'like', "%{$searchNormalized}%");
                                })
                                ->orWhereHas('phones', function ($phoneQuery) use ($searchNormalized) {
                                    $phoneQuery->where('phone', 'like', "%{$searchNormalized}%");
                                })
                                ->orWhereHas('emails', function ($emailQuery) use ($searchNormalized) {
                                    $emailQuery->where('email', 'like', "%{$searchNormalized}%");
                                });
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
                        if (empty($source)) return $q;

                        return $q->where(function ($subQ) use ($source) {
                            if ($source === 'sale') {
                                $subQ->where('source_type', 'App\\Models\\Sale');
                            } elseif ($source === 'order') {
                                $subQ->where('source_type', 'App\\Models\\Order');
                            } elseif ($source === 'other') {
                                $subQ->whereNull('source_type')
                                    ->orWhereNotIn('source_type', ['App\\Models\\Sale', 'App\\Models\\Order']);
                            }
                        });
                    })
                    ->when($project_id, function ($q, $project_id) {
                        return $q->where('transactions.project_id', $project_id);
                    })
                    ->when($is_debt !== null, function ($q) use ($is_debt) {
                        if ($is_debt === 'true' || $is_debt === '1' || $is_debt === 1 || $is_debt === true) {
                            return $q->where('transactions.is_debt', true);
                        } elseif ($is_debt === 'false' || $is_debt === '0' || $is_debt === 0 || $is_debt === false) {
                            return $q->where('transactions.is_debt', false);
                        }
                        return $q;
                    })
                    ->where(function ($q) use ($userUuid) {
                        $q->whereNull('transactions.project_id')
                            ->orWhereHas('project.projectUsers', function ($subQuery) use ($userUuid) {
                                $subQuery->where('user_id', $userUuid);
                            });
                    })
                    ->orderBy('transactions.id', 'desc');

                /** @var \Illuminate\Pagination\LengthAwarePaginator $paginatedResults */
                $paginatedResults = $query->paginate($perPage, ['*'], 'page', (int)$page);

                $paginatedResults->getCollection()->load([
                    'client:id,first_name,last_name,contact_person,client_type,is_supplier,is_conflict,address,note,status,discount_type,discount,created_at,updated_at',
                    'client.phones:id,client_id,phone',
                    'client.emails:id,client_id,email',
                    'currency:id,name,code,symbol',
                    'cashRegister:id,name,currency_id',
                    'cashRegister.currency:id,name,code,symbol',
                    'category:id,name,type',
                    'project:id,name',
                    'user:id,name',
                    'cashTransfersFrom:id,tr_id_from',
                    'cashTransfersTo:id,tr_id_to'
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
                        'cash_currency_code' => $transaction->cashRegister?->currency?->code,
                        'cash_currency_symbol' => $transaction->cashRegister?->currency?->symbol,
                        'orig_amount' => $transaction->orig_amount,
                        'orig_currency_id' => $transaction->currency?->id,
                        'orig_currency_name' => $transaction->currency?->name,
                        'orig_currency_code' => $transaction->currency?->code,
                        'orig_currency_symbol' => $transaction->currency?->symbol,
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
            }, (int)$page);
        } catch (\Exception $e) {
            throw $e;
        }
    }



    /**
     * Создать транзакцию
     *
     * @param array $data Данные транзакции
     * @param bool $return_id Вернуть ID транзакции вместо true
     * @param bool $skipClientUpdate Пропустить обновление баланса клиента
     * @return int|bool ID транзакции или true
     * @throws \Exception
     */
    public function createItem($data, $return_id = false, bool $skipClientUpdate = false)
    {
        $cashRegister = CashRegister::findOrFail($data['cash_id']);
        $originalAmount = $data['orig_amount'];
        $defaultCurrencyId = Currency::where('is_default', true)->value('id');

        $currencyIds = array_unique([
            $data['currency_id'],
            $cashRegister->currency_id,
            $defaultCurrencyId,
        ]);

        $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');

        $fromCurrency = $currencies[$data['currency_id']];
        $toCurrency = $currencies[$cashRegister->currency_id];
        $defaultCurrency = $currencies[$defaultCurrencyId];

        $roundingService = new RoundingService();
        $companyId = $this->getCurrentCompanyId();
        $transactionDate = $data['date'] ?? now();

        if ($fromCurrency->id === $toCurrency->id) {
            $convertedAmount = $originalAmount;
        } else {
            $convertedAmount = CurrencyConverter::convert($originalAmount, $fromCurrency, $toCurrency, $defaultCurrency, $companyId, $transactionDate);
        }

        $convertedAmount = $roundingService->roundForCompany($companyId, (float) $convertedAmount);

        if ($fromCurrency->id !== $defaultCurrency->id) {
            $convertedAmountDefault = CurrencyConverter::convert($originalAmount, $fromCurrency, $defaultCurrency, null, $companyId, $transactionDate);
        } else {
            $convertedAmountDefault = $originalAmount;
        }

        // Round default-currency amount according to company rules for client balance updates
        $roundedConvertedAmountDefault = $roundingService->roundForCompany($companyId, (float) $convertedAmountDefault);

        DB::beginTransaction();

        try {
            $transaction = new Transaction();
            $transaction->type = $data['type'];
            $transaction->user_id = $data['user_id'];
            $transaction->orig_amount = $originalAmount;
            $transaction->amount = $convertedAmount;
            $transaction->currency_id = $data['currency_id'];
            $transaction->cash_id = $cashRegister->id;

            if (isset($data['is_adjustment']) && $data['is_adjustment']) {
                $adjustmentCategoryId = $data['type'] == 1 ? 22 : 21;
                $transaction->category_id = $adjustmentCategoryId;
            } else {
                $transaction->category_id = $data['category_id'];
            }

            $transaction->project_id = $data['project_id'];
            $transaction->client_id = $data['client_id'];
            $transaction->note = !empty($data['note']) ? $data['note'] : null;
            $transaction->date = $data['date'];
            $transaction->is_debt = $data['is_debt'] ?? false;
            $transaction->source_type = $data['source_type'] ?? null;
            $transaction->source_id = $data['source_id'] ?? null;

            $transaction->setSkipClientBalanceUpdate(true);
            $transaction->setSkipCashBalanceUpdate(true);
            $transaction->save();

            if (!($data['is_debt'] ?? false) && $cashRegister) {
                if ((int)$data['type'] === 1) {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance + ' . ($convertedAmount + 0))
                    ]);
                } else {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance - ' . ($convertedAmount + 0))
                    ]);
                }
            }

                $skipForOrderProject = (($data['source_type'] ?? null) === \App\Models\Order::class) && !empty($data['project_id']);
            if ($skipForOrderProject) {
                $companyId = $this->getCurrentCompanyId();
                $company = $companyId ? Company::findOrFail($companyId) : null;
                $skipForOrderProject = $company ? (bool)$company->skip_project_order_balance : $skipForOrderProject;
            }
            if (! $skipClientUpdate && ! empty($data['client_id']) && !$skipForOrderProject) {
                if (($data['is_debt'] ?? false)) {
                    if ($data['type'] === 1) {
                        DB::table('clients')->where('id', $data['client_id'])->update([
                            'balance' => DB::raw('balance + ' . ($roundedConvertedAmountDefault + 0))
                        ]);
                    } else {
                        DB::table('clients')->where('id', $data['client_id'])->update([
                            'balance' => DB::raw('balance - ' . ($roundedConvertedAmountDefault + 0))
                        ]);
                    }
                } else {
                    if ($data['type'] === 1) {
                        DB::table('clients')->where('id', $data['client_id'])->update([
                            'balance' => DB::raw('balance - ' . ($roundedConvertedAmountDefault + 0))
                        ]);
                    } else {
                        DB::table('clients')->where('id', $data['client_id'])->update([
                            'balance' => DB::raw('balance + ' . ($roundedConvertedAmountDefault + 0))
                        ]);
                    }
                }

                CacheService::invalidateClientsCache();
                $this->invalidateClientBalanceCache($data['client_id']);
                CacheService::invalidateProjectsCache();
            }

            DB::commit();

            $this->invalidateTransactionsCache();
            if (!empty($data['client_id'])) {
                $this->invalidateClientBalanceCache($data['client_id']);
            }

            CacheService::invalidateCashRegistersCache();

            if (!empty($data['project_id'])) {
                $projectsRepository = new \App\Repositories\ProjectsRepository();
                $projectsRepository->invalidateProjectCache($data['project_id']);
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
     * @param int $id ID транзакции
     * @param array $data Данные для обновления
     * @return bool
     * @throws \Exception
     */
    public function updateItem($id, $data)
    {
        $transaction = Transaction::findOrFail($id);

        $needsBalanceUpdate = isset($data['orig_amount']) || isset($data['currency_id']) || isset($data['is_debt']) || isset($data['client_id']);

        if ($needsBalanceUpdate) {
            return $this->updateItemWithBalanceRecalculation($id, $data);
        }

        $transaction->client_id = $data['client_id'];
        $transaction->category_id = $data['category_id'];
        $transaction->project_id = $data['project_id'];
        $transaction->date = $data['date'];
        $transaction->note = !empty($data['note']) ? $data['note'] : null;

        if (isset($data['is_debt'])) {
            $transaction->is_debt = $data['is_debt'];
        }

        if (array_key_exists('source_type', $data)) {
            $transaction->source_type = $data['source_type'];
        }
        if (array_key_exists('source_id', $data)) {
            $transaction->source_id = $data['source_id'];
        }

        $transaction->save();

        $this->invalidateTransactionsCache();
        if ($transaction->client_id) {
            $this->invalidateClientBalanceCache($transaction->client_id);
        }

        CacheService::invalidateCashRegistersCache();

        if ($transaction->project_id) {
            $projectsRepository = new ProjectsRepository();
            $projectsRepository->invalidateProjectCache($transaction->project_id);
        }

        return true;
    }

    /**
     * Обновить транзакцию с пересчетом баланса
     *
     * @param int $id ID транзакции
     * @param array $data Данные для обновления
     * @return bool
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

            if (!$oldIsDebt) {
                if ($oldType == 1) {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance - ' . ($oldAmount + 0))
                    ]);
                } else {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance + ' . ($oldAmount + 0))
                    ]);
                }
            }

            $transaction->client_id = $data['client_id'];
            $transaction->category_id = $data['category_id'];
            $transaction->project_id = $data['project_id'];
            $transaction->date = $data['date'];
            $transaction->note = !empty($data['note']) ? $data['note'] : null;

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

            $defaultCurrencyId = Currency::where('is_default', true)->value('id');
            $currencyIds = array_unique([
                $newCurrencyId,
                $cashRegister->currency_id,
                $defaultCurrencyId,
            ]);

            $currencies = Currency::whereIn('id', $currencyIds)->get()->keyBy('id');
            $fromCurrency = $currencies[$newCurrencyId];
            $toCurrency = $currencies[$cashRegister->currency_id];

            if ($fromCurrency->id === $toCurrency->id) {
                $newConvertedAmount = $newOrigAmount;
            } else {
                $newConvertedAmount = CurrencyConverter::convert($newOrigAmount, $fromCurrency, $toCurrency);
            }

            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $newConvertedAmount = $roundingService->roundForCompany($companyId, (float) $newConvertedAmount);

            $transaction->amount = $newConvertedAmount;

            $shouldSkipClientBalanceUpdate = $transaction->is_debt;
            $transaction->setSkipClientBalanceUpdate($shouldSkipClientBalanceUpdate);

            $transaction->save();

            if (!$transaction->is_debt) {
                if ($transaction->type == 1) {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance + ' . ($newConvertedAmount + 0))
                    ]);
                } else {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance - ' . ($newConvertedAmount + 0))
                    ]);
                }
            }

            $oldIsRegularTransaction = empty($oldSourceType);
            if ($oldClientId && $oldClientId !== $transaction->client_id && ($oldIsDebt || $oldIsRegularTransaction)) {
                if ($oldCurrencyId !== $defaultCurrencyId) {
                    $oldConvertedAmountDefault = CurrencyConverter::convert($oldOrigAmount, $currencies[$oldCurrencyId], $currencies[$defaultCurrencyId]);
                } else {
                    $oldConvertedAmountDefault = $oldOrigAmount;
                }

                if ($oldIsDebt) {
                    if ($oldType == 1) {
                        $oldConvertedAmountDefault = (new RoundingService())->roundForCompany($this->getCurrentCompanyId(), (float) $oldConvertedAmountDefault);
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance - ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    } else {
                        $oldConvertedAmountDefault = (new RoundingService())->roundForCompany($this->getCurrentCompanyId(), (float) $oldConvertedAmountDefault);
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance + ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    }
                } else {
                    if ($oldType == 1) {
                        $oldConvertedAmountDefault = (new RoundingService())->roundForCompany($this->getCurrentCompanyId(), (float) $oldConvertedAmountDefault);
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance + ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    } else {
                        $oldConvertedAmountDefault = (new RoundingService())->roundForCompany($this->getCurrentCompanyId(), (float) $oldConvertedAmountDefault);
                        DB::table('clients')->where('id', $oldClientId)->update([
                            'balance' => DB::raw('balance - ' . ($oldConvertedAmountDefault + 0))
                        ]);
                    }
                }

                $this->invalidateClientBalanceCache($oldClientId);
            }

            if ($transaction->client_id) {
                $skipForOrderProject = ($transaction->source_type === \App\Models\Order::class) && !empty($transaction->project_id);
                if ($skipForOrderProject) {
                    $companyId = $this->getCurrentCompanyId();
                    $company = $companyId ? Company::find($companyId) : null;
                    $skipForOrderProject = $company ? (bool)$company->skip_project_order_balance : $skipForOrderProject;
                }
                if ($transaction->is_debt && !$skipForOrderProject) {
                    $if_need_rollback = $oldClientId === $transaction->client_id && ($oldIsDebt || empty($oldSourceType));
                    if ($if_need_rollback) {
                        if ($oldCurrencyId !== $defaultCurrencyId) {
                            $oldConvertedAmountDefault = CurrencyConverter::convert($oldOrigAmount, $currencies[$oldCurrencyId], $currencies[$defaultCurrencyId]);
                        } else {
                            $oldConvertedAmountDefault = $oldOrigAmount;
                        }

                        $oldConvertedAmountDefault = (new RoundingService())->roundForCompany($this->getCurrentCompanyId(), (float) $oldConvertedAmountDefault);
                        if ($transaction->type == 1) {
                            DB::table('clients')->where('id', $transaction->client_id)->update([
                                'balance' => DB::raw('balance - ' . ($oldConvertedAmountDefault + 0))
                            ]);
                        } else {
                            DB::table('clients')->where('id', $transaction->client_id)->update([
                                'balance' => DB::raw('balance + ' . ($oldConvertedAmountDefault + 0))
                            ]);
                        }
                    }

                    if ($newCurrencyId !== $defaultCurrencyId) {
                        $newConvertedAmountDefault = CurrencyConverter::convert($newOrigAmount, $fromCurrency, $currencies[$defaultCurrencyId]);
                    } else {
                        $newConvertedAmountDefault = $newOrigAmount;
                    }

                    $newConvertedAmountDefault = (new RoundingService())->roundForCompany($this->getCurrentCompanyId(), (float) $newConvertedAmountDefault);
                    if ($transaction->type == 1) {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance + ' . ($newConvertedAmountDefault + 0))
                        ]);
                    } else {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance - ' . ($newConvertedAmountDefault + 0))
                        ]);
                    }

                    CacheService::invalidateClientsCache();
                    $this->invalidateClientBalanceCache($transaction->client_id);
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
                $projectsRepository = new ProjectsRepository();
                $projectsRepository->invalidateProjectCache($transaction->project_id);
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
     * @param int $id ID транзакции
     * @param bool $skipClientUpdate Пропустить обновление баланса клиента
     * @return bool
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

            $roundingService = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $transactionDate = $transaction->date ?? now();

            $convertedAmount = $transaction->amount;

            if ($fromCurrency->id !== $defaultCurrency->id) {
                $convertedAmountDefault = CurrencyConverter::convert($transaction->orig_amount, $fromCurrency, $defaultCurrency, null, $companyId, $transactionDate);
            } else {
                $convertedAmountDefault = $transaction->orig_amount;
            }

            $convertedAmountDefault = $roundingService->roundForCompany($companyId, (float) $convertedAmountDefault);

            if (!$transaction->is_debt) {
                if ($transaction->type == 1) {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance - ' . ($convertedAmount + 0))
                    ]);
                } else {
                    DB::table('cash_registers')->where('id', $cashRegister->id)->update([
                        'balance' => DB::raw('balance + ' . ($convertedAmount + 0))
                    ]);
                }
            }

            $shouldSkipClientBalanceUpdate = $transaction->is_debt;
            $transaction->setSkipClientBalanceUpdate($shouldSkipClientBalanceUpdate);
            $transaction->setSkipCashBalanceUpdate(true);

            DB::table('transactions')->where('id', $transaction->id)->update(['is_deleted' => true]);

            $skipForOrderProject = ($transaction->source_type === \App\Models\Order::class) && !empty($transaction->project_id);
            if ($skipForOrderProject) {
                $company = $companyId ? Company::findOrFail($companyId) : null;
                $skipForOrderProject = $company ? (bool)$company->skip_project_order_balance : $skipForOrderProject;
            }
            if (! $skipClientUpdate && $transaction->client_id && !$skipForOrderProject) {
                if ($transaction->is_debt) {
                    if ($transaction->type == 1) {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance - ' . ($convertedAmountDefault + 0))
                        ]);
                    } else {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance + ' . ($convertedAmountDefault + 0))
                        ]);
                    }
                } else {
                    if ($transaction->type == 1) {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance + ' . ($convertedAmountDefault + 0))
                        ]);
                    } else {
                        DB::table('clients')->where('id', $transaction->client_id)->update([
                            'balance' => DB::raw('balance - ' . ($convertedAmountDefault + 0))
                        ]);
                    }
                }

                CacheService::invalidateClientsCache();
                CacheService::invalidateClientBalanceCache($transaction->client_id);
                CacheService::invalidateProjectsCache();
            }

            DB::commit();

            $this->invalidateTransactionsCache();
            if ($transaction->client_id) {
                $this->invalidateClientBalanceCache($transaction->client_id);
            }

            CacheService::invalidateCashRegistersCache();

            if ($transaction->project_id) {
                $projectsRepository = new ProjectsRepository();
                $projectsRepository->invalidateProjectCache($transaction->project_id);
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
     * @param int $userId ID пользователя
     * @param int $orderId ID заказа
     * @return float
     */
    public function getTotalByOrderId($userId, $orderId)
    {
        return Transaction::where('source_type', 'App\Models\Order')
            ->where('source_id', $orderId)
            ->where('is_debt', 0)
            ->where('is_deleted', false)
            ->sum('orig_amount');
    }

    /**
     * Проверить, является ли транзакция перемещением между кассами
     *
     * @param Transaction $transaction Транзакция
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
     * @param Transaction $transaction Транзакция
     * @return bool
     */
    private function isSale($transaction)
    {
        return $transaction->source_type === 'App\Models\Sale';
    }

    /**
     * Проверить, является ли транзакция оприходованием
     *
     * @param Transaction $transaction Транзакция
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
     * @param int $id ID транзакции
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
     * @param array $ids Массив ID транзакций
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
            'cash_register_currencies.code as cash_currency_code',
            'cash_register_currencies.symbol as cash_currency_symbol',
            'transactions.orig_amount as orig_amount',
            'currencies.id as orig_currency_id',
            'currencies.name as orig_currency_name',
            'currencies.code as orig_currency_code',
            'currencies.symbol as orig_currency_symbol',
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

        if (!empty($clientIds)) {
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
                    'clients.balance as balance'
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
}
