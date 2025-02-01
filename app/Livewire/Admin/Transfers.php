<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\CashTransfer;
use App\Models\CashRegister;
use App\Models\FinancialTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB; // Add this import at the top

class Transfers extends Component
{
    public $transfers;
    public $cashRegisters;
    public $selectedTransferId;
    public $selectedTransfer;
    public $showForm = false; // Renamed from $showTransferForm
    public $showConfirmationModal = false; // Renamed from $showConfirmation
    public $isDirty = false;

    public $from_cash_register_id;
    public $to_cash_register_id;
    public $amount;
    public $note;

    protected $rules = [
        'from_cash_register_id' => 'required|exists:cash_registers,id',
        'to_cash_register_id' => 'required|exists:cash_registers,id|different:from_cash_register_id',
        'amount' => 'required|numeric|min:0.01',
        'note' => 'nullable|string',
    ];

    public function mount()
    {
        $this->cashRegisters = CashRegister::all();
        $this->transfers = CashTransfer::with('fromCashRegister', 'toCashRegister', 'currency')->get();
    }

    public function openForm() // Renamed from openTransferForm
    {
        $this->resetForm();
        $this->showForm = true; // Updated from $showTransferForm
        $this->isDirty = false;
    }

    public function saveTransfer()
    {
        $this->validate();

        DB::beginTransaction();

        try {
            if ($this->selectedTransferId) {
                $transfer = CashTransfer::find($this->selectedTransferId);
                if ($transfer) {
                    // Update associated transactions
                    $fromTransaction = $transfer->fromTransaction;
                    $toTransaction = $transfer->toTransaction;

                    $fromTransaction->amount = $this->amount;
                    $fromTransaction->save();

                    $toTransaction->amount = $this->amount;
                    $toTransaction->save();

                    // Update transfer details
                    $transfer->from_cash_register_id = $this->from_cash_register_id;
                    $transfer->to_cash_register_id = $this->to_cash_register_id;
                    $transfer->amount = $this->amount;
                    $transfer->note = $this->note;
                    $transfer->save();

                    session()->flash('message', 'Трансфер успешно обновлен.');
                }
            } else {
                // Use the transfer logic from CashRegisters
                $transfer = $this->handleSaveTransferCustom(
                    $this->from_cash_register_id,
                    $this->to_cash_register_id,
                    $this->amount,
                    $this->note
                );

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

    public function handleSaveTransferCustom($fromCashRegisterId, $toCashRegisterId, $amount, $note)
    {
        // Validate input
        $this->validate([
            'from_cash_register_id' => 'required|exists:cash_registers,id',
            'to_cash_register_id' => 'required|exists:cash_registers,id|different:from_cash_register_id',
            'amount' => 'required|numeric|min:0.01',
            'note' => 'nullable|string',
        ]);

        $fromCashRegister = CashRegister::find($fromCashRegisterId);
        $toCashRegister = CashRegister::find($toCashRegisterId);

        if ($amount > $fromCashRegister->balance) {
            throw new \Exception('Недостаточно средств для перевода.');
        }

        $fromCurrency = $fromCashRegister->currency;
        $toCurrency = $toCashRegister->currency;
        $amountInDefaultCurrency = $amount / $fromCurrency->exchange_rate;
        $amountInTargetCurrency = $amountInDefaultCurrency * $toCurrency->exchange_rate;

        $fromCashRegister->balance -= $amount;
        $toCashRegister->balance += $amountInTargetCurrency;

        $fromCashRegister->save();
        $toCashRegister->save();

        $transferNote = "Трансфер из кассы '{$fromCashRegister->name}' в кассу '{$toCashRegister->name}'.";

        $fromTransaction = FinancialTransaction::create([
            'type' => '0',
            'amount' => $amount,
            'cash_register_id' => $fromCashRegisterId,
            'note' => $note . ' ' . $transferNote,
            'transaction_date' => now()->toDateString(),
            'currency_id' => $fromCashRegister->currency_id,
            'user_id' => Auth::id(),
        ]);

        $toTransaction = FinancialTransaction::create([
            'type' => '1',
            'amount' => $amountInTargetCurrency,
            'cash_register_id' => $toCashRegisterId,
            'note' => $note . ' ' . $transferNote,
            'transaction_date' => now()->toDateString(),
            'currency_id' => $toCashRegister->currency_id,
            'user_id' => Auth::id(),
        ]);

        return CashTransfer::create([
            'from_cash_register_id' => $fromCashRegisterId,
            'to_cash_register_id' => $toCashRegisterId,
            'from_transaction_id' => $fromTransaction->id,
            'to_transaction_id' => $toTransaction->id,
            'user_id' => Auth::id(),
            'amount' => $amount,
            'note' => $note,
        ]);
    }

    public function deleteTransfer($transferId)
    {
        $transfer = CashTransfer::find($transferId);
        if ($transfer) {

            $transfer->fromTransaction->delete();
            $transfer->toTransaction->delete();
            $transfer->delete();
            session()->flash('message', 'Трансфер и связанные транзакции успешно удалены.');
            // $this->mount(); 
        }
    }

    public function selectTransfer($transferId)
    {
        $transfer = CashTransfer::with('fromCashRegister', 'toCashRegister')->find($transferId);

        if ($transfer) {
            $this->selectedTransferId = $transfer->id;
            $this->from_cash_register_id = $transfer->from_cash_register_id;
            $this->to_cash_register_id = $transfer->to_cash_register_id;
            $this->amount = $transfer->amount;
            $this->note = $transfer->note;
            $this->showForm = true;
            $this->isDirty = false;
        } else {
            session()->flash('error', 'Трансфер не найден.');
        }
    }

    private function resetForm()
    {
        $this->selectedTransferId = null;
        $this->from_cash_register_id = '';
        $this->to_cash_register_id = '';
        $this->amount = '';
        $this->note = '';
    }

    public function deleteForm()
    {
        // Logic to handle form deletion
        // For example, reset the form and hide it
        $this->resetForm();
        $this->showForm = false;
        $this->isDirty = false;
        session()->flash('message', 'Форма успешно удалена.');
    }

    public function closeForm()
    {
        if ($this->isDirty) {
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
            $this->showForm = false; // Updated from $showTransferForm
        }
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->resetForm();
            $this->showForm = false; // Updated from $showTransferForm
            $this->isDirty = false;
        }
        $this->showConfirmationModal = false; // Updated from $showConfirmation
    }

    public function render()
    {
        return view('livewire.admin.finance.transfers');
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }
}
