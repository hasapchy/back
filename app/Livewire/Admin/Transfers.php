<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\CashTransfer;
use App\Models\CashRegister;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\CurrencyConverter;
use App\Models\Currency;

class Transfers extends Component
{
    public $transfers;
    public $cashRegisters;
    public $transferId;
    public $showForm = false;
    public $isDirty = false;
    public $cashFrom;
    public $cashTo;
    public $amount;
    public $note;
    public $showConfirmationModal;
    public $date;

    protected $rules = [
        'cashFrom' => 'required|exists:cash_registers,id',
        'cashTo' => 'required|exists:cash_registers,id|different:cashFrom',
        'amount' => 'required|numeric|min:0.01',
        'note' => 'nullable|string',
        'date' => 'required|date',
    ];

    public function mount()
    {
        $this->cashRegisters = CashRegister::whereJsonContains('users', (string) Auth::id())->get();
        $this->transfers = CashTransfer::with('fromCashRegister', 'toCashRegister', 'currency')->get();
        $this->date = now()->format('Y-m-d H:i:s');
    }

    public function render()
    {
        return view('livewire.admin.finance.transfers');
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
        $this->isDirty = false;
    }

    public function closeForm()
    {
        if ($this->isDirty) {
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
            $this->showForm = false;
        }
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
            $this->showForm = false;
            $this->isDirty = false;
        }
        $this->showConfirmationModal = false;
    }

    private function resetForm()
    {
        $this->transferId = null;
        $this->cashFrom = null;
        $this->cashTo = null;
        $this->amount = 0;
        $this->note = '';
    }

    public function saveTransfer()
    {
        $this->validate();

        DB::beginTransaction();

        try {
            if ($this->transferId) {
                $transfer = CashTransfer::find($this->transferId);
                $this->update($transfer);
                session()->flash('message', 'Трансфер успешно обновлен.');
            } else {
                $this->create();
                session()->flash('message', 'Трансфер успешно создан.');
            }

            DB::commit();

            $this->resetForm();
            $this->showForm = false;
            $this->isDirty = false;
            $this->mount(); // Refresh transfers
        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Произошла ошибка при сохранении трансфера: ' . $e->getMessage());
        }
    }

    private function create()
    {
        $fromCashRegister = CashRegister::find($this->cashFrom);
        $toCashRegister = CashRegister::find($this->cashTo);

        $fromCurrency = $fromCashRegister->currency;
        $toCurrency = $toCashRegister->currency;

        // Если валюты касс отличаются, конвертируем сумму:
        if ($fromCurrency->id !== $toCurrency->id) {
            $amountInTargetCurrency = CurrencyConverter::convert($this->amount, $fromCurrency, $toCurrency);
        } else {
            $amountInTargetCurrency = $this->amount;
        }

        // Обновляем балансы касс:
        $fromCashRegister->balance -= $this->amount;
        $toCashRegister->balance += $amountInTargetCurrency;

        $fromCashRegister->save();
        $toCashRegister->save();

        $transferNote = "Трансфер из кассы '{$fromCashRegister->name}' в кассу '{$toCashRegister->name}'.";

        $fromTransaction = Transaction::create([
            'type'             => '0',
            'amount'           => $this->amount,
            'orig_amount'      => $this->amount,
            'cash_id'          => $this->cashFrom,
            'note'             => $this->note . ' ' . $transferNote,
            'date'             => $this->date,
            'currency_id'      => $fromCashRegister->currency_id,
            'user_id'          => Auth::id(),
        ]);

        // Создаем транзакцию для кассы-получателя
        $toTransaction = Transaction::create([
            'type'             => '1',
            'amount'           => $amountInTargetCurrency,
            'orig_amount'      => $this->amount,
            'cash_id'          => $this->cashTo,
            'note'             => $this->note . ' ' . $transferNote,
            'date'             => $this->date,
            'currency_id'      => $toCashRegister->currency_id,
            'user_id'          => Auth::id(),
        ]);

        // Создаем запись трансфера
        CashTransfer::create([
            'cash_id_from' => $this->cashFrom,
            'cash_id_to'   => $this->cashTo,
            'tr_id_from'   => $fromTransaction->id,
            'tr_id_to'     => $toTransaction->id,
            'user_id'      => Auth::id(),
            'amount'       => $this->amount,
            'note'         => $this->note,
            'date'         => now(),
        ]);
    }

    private function update($transfer)
    {
        $fromTransaction = $transfer->fromTransaction;
        $toTransaction = $transfer->toTransaction;
        $fromTransaction->amount = $this->amount;
        $fromTransaction->save();
        $toTransaction->amount = $this->amount;
        $toTransaction->save();
        $transfer->cashFrom = $this->cashFrom;
        $transfer->cashTo = $this->cashTo;
        $transfer->amount = $this->amount;
        $transfer->note = $this->note;
        $transfer->save();
    }

    public function delete()
    {
        DB::beginTransaction();
        try {
            $transfer = CashTransfer::find($this->transferId);
            if ($transfer) {
                $fromTransaction = $transfer->fromTransaction;
                $toTransaction = $transfer->toTransaction;
                $transfer->delete();
                $fromTransaction->delete();
                $toTransaction->delete();
                session()->flash('message', 'Трансфер и связанные транзакции успешно удалены.');
                $this->closeForm();
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            session()->flash('error', 'Ошибка удаления трансфера: ' . $e->getMessage());
        }
    }

    public function edit($transferId)
    {
        $transfer = CashTransfer::with('fromCashRegister', 'toCashRegister')->find($transferId);
        $this->transferId = $transfer->id;
        $this->cashFrom = $transfer->cashFrom;
        $this->cashTo = $transfer->cash_id_to;
        $this->amount = $transfer->amount;
        $this->note = $transfer->note;
        $this->showForm = true;
        $this->isDirty = false;
    }


    public function updated($propertyName)
    {
        $this->isDirty = true;
    }
}
