<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Currency;
use App\Models\CurrencyHistory;
use Illuminate\Support\Facades\DB;

class Currencies extends Component
{
    public $currencies;
    public $exchangeRates = [];
    public $showForm = false;
    public $currencyId;
    public $currency_name;
    public $exchange_rate;
    public $currency_code;
    public $exchangeRateHistories = []; 

    public function mount()
    {
        $this->currencies = Currency::all();
        foreach ($this->currencies as $currency) {
            $this->exchangeRates[$currency->id] = $currency->currentExchangeRate()->exchange_rate ?? 0;
        }
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->showForm = false;
    }

    public function resetForm()
    {
        $this->currencyId = null;
        $this->currency_name = '';
        $this->exchange_rate = '';
        $this->currency_code = '';
    }

    public function saveCurrency()
    {
        $this->validate([
            'currency_name' => 'required|string|max:255',
            'exchange_rate' => 'required|numeric|min:0.000001',
            'currency_code' => 'required|string|max:10',
        ]);

        DB::transaction(function () {
            if ($this->currencyId) {
                $currency = Currency::findOrFail($this->currencyId);
                $currency->currency_name = $this->currency_name;
                $currency->currency_code = $this->currency_code;
                $currency->save();

                $currentRate = $currency->currentExchangeRate();
                if ($currentRate) {
                    // Set end_date of the existing exchange rate
                    $currentRate->end_date = now()->toDateString();
                    $currentRate->save();
                }
            } else {
                $currency = Currency::create([
                    'currency_name' => $this->currency_name,
                    'currency_code' => $this->currency_code,
                ]);
            }

            CurrencyHistory::create([
                'currency_id' => $currency->id,
                'exchange_rate' => $this->exchange_rate,
                'start_date' => now()->toDateString(),
            ]);
        });

        $this->closeForm();
        $this->mount();
        session()->flash('success', 'Валюта сохранена.');
    }

    public function updateExchangeRate($currencyId)
    {
        $this->validate([
            "exchangeRates.$currencyId" => 'required|numeric|min:0.000001',
        ]);

        DB::transaction(function () use ($currencyId) { // Start transaction
            $currency = Currency::findOrFail($currencyId);

            $currentRate = $currency->currentExchangeRate();
            if ($currentRate) {

                $currentRate->end_date = now()->toDateString();
                $currentRate->save();
            }

            CurrencyHistory::create([
                'currency_id' => $currencyId,
                'exchange_rate' => $this->exchangeRates[$currencyId],
                'start_date' => now()->toDateString(),
            ]);
        });

        $this->mount();
        session()->flash('success', 'Курс обновлён.');
    }


    public function editCurrency($currencyId)
    {
        $currency = Currency::findOrFail($currencyId);
        $this->currencyId = $currency->id;
        $this->currency_name = $currency->currency_name;
        $this->exchange_rate = $currency->currentExchangeRate()->exchange_rate ?? 0;
        $this->currency_code = $currency->currency_code;
        $this->showForm = true;
        $this->exchangeRateHistories = $currency->exchangeRateHistories()->orderBy('start_date', 'desc')->get();
    }

    public function render()
    {
        return view('livewire.admin.finance.currencies', [
        ]);
    }
}
