<?php

namespace App\Repositories;

use App\Models\CashRegister;
use App\Models\CashRegisterUser;
use App\Models\Transaction;
use App\Services\CacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CahRegistersRepository extends BaseRepository
{

    public function getItemsWithPagination($userUuid, $perPage = 20, $page = 1)
    {
        try {
            $query = CashRegister::with(['currency:id,name,code,symbol', 'users:id,name'])
                ->whereHas('cashRegisterUsers', function($query) use ($userUuid) {
                    $query->where('user_id', $userUuid);
                });

            $query = $this->addCompanyFilterDirect($query, 'cash_registers');

            return $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', (int)$page);
        } catch (\Exception $e) {
            return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
        }
    }

    public function getAllItems($userUuid)
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('cash_registers')) {
                throw new \Exception('Table cash_registers does not exist');
            }

            $cacheKey = $this->generateCacheKey('cash_registers_all', [$userUuid]);

            return CacheService::getReferenceData($cacheKey, function() use ($userUuid) {
                $query = CashRegister::with(['currency:id,name,code,symbol', 'users:id,name'])
                    ->whereHas('cashRegisterUsers', function($query) use ($userUuid) {
                        $query->where('user_id', $userUuid);
                    });

                $query = $this->addCompanyFilterDirect($query, 'cash_registers');

                return $query->get();
            });
        } catch (\Exception $e) {
            return \Illuminate\Support\Collection::make();
        }
    }

    public function getCashBalance(
        $userUuid,
        $cash_register_ids = [],
        $all = false,
        $startDate = null,
        $endDate = null,
        $transactionType = null,
        $source = null
    ) {
        $query = CashRegister::with(['currency:id,name,code,symbol'])
            ->whereHas('cashRegisterUsers', function($q) use ($userUuid) {
                $q->where('user_id', $userUuid);
            });

        $query = $this->addCompanyFilterDirect($query, 'cash_registers');

        if (!$all && !empty($cash_register_ids)) {
            $query->whereIn('id', $cash_register_ids);
        }

        $items = $query->get()
            ->map(function ($cashRegister) use ($userUuid, $startDate, $endDate, $transactionType, $source) {

                $txBase = Transaction::where('cash_id', $cashRegister->id)
                    ->where('is_deleted', false)
                    ->when($startDate || $endDate, function ($q) use ($startDate, $endDate) {
                        if ($startDate && $endDate) {
                            return $q->whereBetween('date', [$startDate, $endDate]);
                        } elseif ($startDate) {
                            return $q->where('date', '>=', $startDate);
                        } elseif ($endDate) {
                            return $q->where('date', '<=', $endDate);
                        }
                        return $q;
                    })
                    ->when($transactionType, function ($q) use ($transactionType) {
                        switch ($transactionType) {
                            case 'income':
                                return $q->where('type', 1);
                            case 'outcome':
                                return $q->where('type', 0);
                            case 'transfer':
                                return $q->where(function ($subQ) {
                                    $subQ->whereHas('cashTransfersFrom')
                                        ->orWhereHas('cashTransfersTo');
                                });
                            default:
                                return $q;
                        }
                    })
                    ->when($source, function ($q) use ($source) {
                        if (empty($source)) return $q;

                        return $q->where(function ($subQ) use ($source) {
                            $hasConditions = false;

                            if (in_array('sale', $source)) {
                                $subQ->where('source_type', 'App\\Models\\Sale');
                                $hasConditions = true;
                            }
                            if (in_array('order', $source)) {
                                if ($hasConditions) {
                                    $subQ->orWhere('source_type', 'App\\Models\\Order');
                                } else {
                                    $subQ->where('source_type', 'App\\Models\\Order');
                                }
                                $hasConditions = true;
                            }
                            if (in_array('other', $source)) {
                                if ($hasConditions) {
                                    $subQ->orWhere(function ($otherQ) {
                                        $otherQ->whereNull('source_type')
                                            ->orWhereNotIn('source_type', ['App\\Models\\Sale', 'App\\Models\\Order']);
                                    });
                                } else {
                                    $subQ->whereNull('source_type')
                                        ->orWhereNotIn('source_type', ['App\\Models\\Sale', 'App\\Models\\Order']);
                                }
                            }
                        });
                    });

                $income  = (clone $txBase)->where('type', 1)->where('is_debt', false)->sum('amount');
                $outcome = (clone $txBase)->where('type', 0)->where('is_debt', false)->sum('amount');
                $debtIncome = (clone $txBase)->where('type', 1)->where('is_debt', true)->sum('amount');
                $debtOutcome = (clone $txBase)->where('type', 0)->where('is_debt', true)->sum('amount');
                $debtTotal = $debtIncome - $debtOutcome;

                $balance = [
                    ['value' => $income,  'title' => 'Приход',  'type' => 'income'],
                    ['value' => $outcome, 'title' => 'Расход',  'type' => 'outcome'],
                    ['value' => $cashRegister->balance, 'title' => 'Итого',                     'type' => 'default'],
                ];

                if ($debtTotal != 0) {
                    $balance[] = ['value' => $debtTotal, 'title' => 'Долг', 'type' => 'debt'];
                }

                return [
                    'id'          => $cashRegister->id,
                    'name'        => $cashRegister->name,
                    'currency_id' => $cashRegister->currency_id,
                    'currency_symbol' => $cashRegister->currency ? $cashRegister->currency->symbol : null,
                    'currency_code' => $cashRegister->currency ? $cashRegister->currency->code : null,
                    'balance'     => $balance,
                ];
            });
        return $items;
    }

    public function createItem($data)
    {
        DB::beginTransaction();
        try {
            $item = new CashRegister();
            $item->name = $data['name'];
            $item->balance = $data['balance'];
            $item->currency_id = $data['currency_id'];
            $item->company_id = $this->getCurrentCompanyId();
            $item->save();

            foreach ($data['users'] as $userId) {
                CashRegisterUser::create([
                    'cash_register_id' => $item->id,
                    'user_id' => $userId
                ]);
            }

            DB::commit();

            $this->invalidateCashRegistersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function updateItem($id, $data)
    {
        DB::beginTransaction();
        try {
            $item = CashRegister::find($id);
            $item->name = $data['name'];
            $item->save();

            CashRegisterUser::where('cash_register_id', $id)->delete();

            foreach ($data['users'] as $userId) {
                CashRegisterUser::create([
                    'cash_register_id' => $id,
                    'user_id' => $userId
                ]);
            }

            DB::commit();

            $this->invalidateCashRegistersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function deleteItem($id)
    {
        DB::beginTransaction();
        try {
            $item = CashRegister::find($id);
            $item->delete();

            CashRegisterUser::where('cash_register_id', $id)->delete();

            DB::commit();

            $this->invalidateCashRegistersCache();

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function invalidateCashRegistersCache()
    {
        CacheService::invalidateCashRegistersCache();
    }
}
