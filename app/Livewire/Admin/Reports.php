<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Sale;
use App\Models\Transaction;
use Carbon\Carbon;

class Reports extends Component
{
    public $selectedReport = 'finance';
    public $sales = [];

    // Фильтр по дате
    public $dateFilter = 'all'; // all, today, yesterday, thisWeek, thisMonth, custom
    public $customStartDate;
    public $customEndDate;

    public function mount()
    {
        $this->loadReport();
    }

    public function updatedSelectedReport()
    {
        $this->loadReport();
    }

    public function updatedDateFilter()
    {
        $this->loadReport();
    }

    public function updatedCustomStartDate()
    {
        if ($this->dateFilter === 'custom') {
            $this->loadReport();
        }
    }

    public function updatedCustomEndDate()
    {
        if ($this->dateFilter === 'custom') {
            $this->loadReport();
        }
    }

    public function loadReport()
    {
        if ($this->selectedReport === 'finance') {
            $query = Sale::with(['products.prices', 'user']);

            // Применяем фильтр по дате для отчёта «Финансы»
            if ($this->dateFilter === 'today') {
                $query->whereBetween('date', [
                    Carbon::today()->startOfDay(),
                    Carbon::today()->endOfDay()
                ]);
            } elseif ($this->dateFilter === 'yesterday') {
                $query->whereBetween('date', [
                    Carbon::yesterday()->startOfDay(),
                    Carbon::yesterday()->endOfDay()
                ]);
            } elseif ($this->dateFilter === 'thisWeek') {
                $query->whereBetween('date', [
                    Carbon::now()->startOfWeek(),
                    Carbon::now()->endOfWeek()
                ]);
            } elseif ($this->dateFilter === 'thisMonth') {
                $query->whereBetween('date', [
                    Carbon::now()->startOfMonth(),
                    Carbon::now()->endOfMonth()
                ]);
            } elseif ($this->dateFilter === 'custom' && $this->customStartDate && $this->customEndDate) {
                $query->whereBetween('date', [
                    $this->customStartDate,
                    $this->customEndDate
                ]);
            }

            $this->sales = $query->get()->map(function ($sale) {
                // Итоговая сумма продажи из поля price
                $sum = $sale->price;
                // Себестоимость: сумма (количество * purchase_price) для каждого товара
                $cost = $sale->products->sum(function ($product) {
                    $quantity = $product->pivot->quantity;
                    $purchasePrice = optional($product->prices->first())->purchase_price ?? 0;
                    return $quantity * $purchasePrice;
                });
                $discount = $sale->discount;
                // Прибыль = (price - discount) - себестоимость
                $profit = ($sum - $discount) - $cost;

                return [
                    'name'     => $sale->note ?? 'Без названия',
                    'date'     => $sale->date,
                    'user'     => $sale->user->name ?? 'N/A',
                    'sum'      => $sum,
                    'cost'     => $cost,
                    'discount' => $discount,
                    'profit'   => $profit,
                ];
            })->toArray();
        } elseif ($this->selectedReport === 'cash_flow') {
            // Второй отчет – Движение денежных средств
            $query = Transaction::with('category');

            // Применяем фильтр по дате для отчёта «Движение денежных средств»
            if ($this->dateFilter === 'today') {
                $query->whereBetween('date', [
                    Carbon::today()->startOfDay(),
                    Carbon::today()->endOfDay()
                ]);
            } elseif ($this->dateFilter === 'yesterday') {
                $query->whereBetween('date', [
                    Carbon::yesterday()->startOfDay(),
                    Carbon::yesterday()->endOfDay()
                ]);
            } elseif ($this->dateFilter === 'thisWeek') {
                $query->whereBetween('date', [
                    Carbon::now()->startOfWeek(),
                    Carbon::now()->endOfWeek()
                ]);
            } elseif ($this->dateFilter === 'thisMonth') {
                $query->whereBetween('date', [
                    Carbon::now()->startOfMonth(),
                    Carbon::now()->endOfMonth()
                ]);
            } elseif ($this->dateFilter === 'custom' && $this->customStartDate && $this->customEndDate) {
                $query->whereBetween('date', [
                    $this->customStartDate,
                    $this->customEndDate
                ]);
            }

            // Получаем все транзакции за выбранный период
            $transactions = $query->get();
            // Группируем транзакции по названию категории (при отсутствии категории – 'Без категории')
            $grouped = $transactions->groupBy(function ($txn) {
                return $txn->category->name ?? 'Без категории';
            });

            $result = [];
            foreach ($grouped as $categoryName => $group) {
                $incoming = $group->where('type', 1)->sum('amount');
                $outgoing = $group->where('type', 0)->sum('amount');
                $result[] = [
                    'category' => $categoryName,
                    'incoming' => $incoming,
                    'outgoing' => $outgoing,
                ];
            }


            $this->sales = $result;
        } elseif ($this->selectedReport === 'total_money') {
            // Отчет всего денег — показываем все кассы с баллансами в их собственной валюте (без конвертации)
            $cashRegisters = \App\Models\CashRegister::with('currency')->get();
            $this->sales = $cashRegisters->map(function ($cash) {
                return [
                    'name' => $cash->name,
                    'balance' => $cash->balance,
                    'currency_symbol' => $cash->currency->symbol,
                ];
            })->toArray();
        }
    }

    public function render()
    {
        return view('livewire.admin.reports');
    }
}
