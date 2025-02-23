<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Currency;

class CurrencySwitcher extends Component
{
    public $selectedCurrency = '';
    public $currencies = [];
    
    public function mount()
    {
        $this->currencies = Currency::all();
        $this->selectedCurrency = session('currency') 
            ?? optional(Currency::where('is_default', true)->first())->code;
    }

    public function updatedSelectedCurrency()
    {
        // Сохраните выбор пользователя в сессии или базе
        session(['currency' => $this->selectedCurrency]);
        $this->dispatch('currency-changed');
    }

    public function render()
    {
        return view('livewire.admin.currency-switcher');
    }
}