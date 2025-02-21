<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\CashTransfer;
use App\Models\CashRegister;
use App\Models\FinancialTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

    protected $rules = [
        'cashFrom' => 'required|exists:cash_registers,id',
        'cashTo' => 'required|exists:cash_registers,id|different:cashFrom',
        'amount' => 'required|numeric|min:0.01',
        'note' => 'nullable|string',
    ];

    public function mount()
    {
        $this->cashRegisters = CashRegister::whereJsonContains('users', Auth::id())->get();
        $this->transfers = CashTransfer::with('fromCashRegister', 'toCashRegister', 'currency')->get();
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
        $amountInDefaultCurrency = $this->amount / $fromCurrency->exchange_rate;
        $amountInTargetCurrency = $amountInDefaultCurrency * $toCurrency->exchange_rate;

        $fromCashRegister->balance -= $this->amount;
        $toCashRegister->balance += $amountInTargetCurrency;

        $fromCashRegister->save();
        $toCashRegister->save();

        $transferNote = "Трансфер из кассы '{$fromCashRegister->name}' в кассу '{$toCashRegister->name}'.";

        $fromTransaction = FinancialTransaction::create([
            'type' => '0',
            'amount' => $this->amount,
            'cash_register_id' => $this->cashFrom,
            'note' => $this->note . ' ' . $transferNote,
            'transaction_date' => now()->toDateString(),
            'currency_id' => $fromCashRegister->currency_id,
            'user_id' => Auth::id(),
        ]);

        $toTransaction = FinancialTransaction::create([
            'type' => '1',
            'amount' => $amountInTargetCurrency,
            'cash_register_id' => $this->cashTo,
            'note' => $this->note . ' ' . $transferNote,
            'transaction_date' => now()->toDateString(),
            'currency_id' => $toCashRegister->currency_id,
            'user_id' => Auth::id(),
        ]);

        CashTransfer::create([
            'from_cash_register_id' => $this->cashFrom,
            'to_cash_register_id' => $this->cashTo,
            'from_transaction_id' => $fromTransaction->id,
            'to_transaction_id' => $toTransaction->id,
            'user_id' => Auth::id(),
            'amount' => $this->amount,
            'note' => $this->note,
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
        $this->cashFrom = $transfer->from_cash_register_id;
        $this->cashTo = $transfer->to_cash_register_id;
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
