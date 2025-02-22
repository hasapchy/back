<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\CashRegister;
use App\Models\Currency;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Transaction;

class Cashes extends Component
{
    public $cashId;
    public $name;
    public $balance;
    public $currencyId;
    public $showForm = false;
    public $users = [];
    public $cashRegisters;
    public $currencies;
    public $allUsers = [];

    public function mount()
    {
        $this->cashRegisters = CashRegister::whereJsonContains('users', (string) Auth::id())->get();
        $this->currencies = Currency::all();
        $this->allUsers = User::all();
    }

    public function render()
    {
        return view('livewire.admin.finance.cashes');
    }

    public function openForm($cashId = null)
    {
        $this->resetForm();
        if ($cashId && $cashRegister = CashRegister::find($cashId)) {
            $this->cashId = $cashRegister->id;
            $this->name = $cashRegister->name;
            $this->balance = $cashRegister->balance;
            $this->currencyId = $cashRegister->currency_id;
            $this->users = $cashRegister->users ?? [];
        }
        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->resetForm();
        $this->showForm = false;
    }

    private function resetForm()
    {
        $this->cashId = null;
        $this->name = '';
        $this->balance = '';
        $this->currencyId = null;
        $this->users = [];
    }

    public function create()
    {
        $rules = [
            'name' => 'required|string|max:255',
            'balance' => 'required|numeric',
            'currencyId' => 'required|exists:currencies,id',
        ];
        $this->validate($rules);


        CashRegister::create([
            'name' => $this->name,
            'balance' => $this->balance,
            'currency_id' => $this->currencyId,
            'users' => $this->users,
        ]);
        session()->flash('message', 'Касса успешно создана.');
        $this->closeForm();
    }

    public function update()
    {
        $rules = [
            'name' => 'required|string|max:255',

        ];
        $this->validate($rules);
        if ($cashRegister = CashRegister::find($this->cashId)) {
            $cashRegister->name = $this->name;
            $cashRegister->users = $this->users;
            $cashRegister->save();
            session()->flash('message', 'Касса успешно обновлена.');
        }
        $this->closeForm();
    }

    public function delete($cashId)
    {
        $transactionExists = Transaction::where('cash_id', $cashId)->exists();
        if (!$transactionExists) {
            $cashRegister = CashRegister::find($cashId);
            if ($cashRegister) {
                $cashRegister->delete();
            }
            session()->flash('message', 'Касса успешно удалена.');
        } else {
            session()->flash('error', 'Невозможно удалить кассу с транзакциями.');
        }
        $this->closeForm();
    }
}
