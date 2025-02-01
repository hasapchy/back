<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\TransactionCategory;
use App\Models\CashTransfer;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use App\Models\Client;
use App\Models\User;
use App\Models\Order;
use App\Services\ClientService;

class CashRegisters extends Component
{
    public $name;
    public $balance;
    public $currency_id;
    public $selectedCashRegisterId;
    public $showCreateForm = false;
    public $showTransactionForm = false;
    public $showTransferForm = false;
    public $amount;
    public $note;
    public $to_cash_register_id;
    public $cashRegisters;
    public $currencies;
    public $exchange_rate;
    public $category_id;
    public $transaction_date;
    public $client_id;
    public $clientSearch = '';
    public $clientResults = [];
    public $selectedClient;
    public $clients;
    public $transactionId;
    public $startDate;
    public $endDate;
    public $filteredCategories = [];
    public $editCashRegisterUsers = [];
    public $allUsers = [];
    public $type;
    public $transactions;
    public $isDirty = false;
    public $showConfirmationModal = false;
    public $formBeingClosed = null;
    public $selectedProjectId = null;
    public $projects = [];
    public $searchTerm;
    public $totalIncome = 0;
    public $totalExpense = 0;

    protected $listeners = [
        'refreshCashRegisters' => 'refreshCashRegisters',
        'dateFilterUpdated' => 'updateDateFilter',
        'confirmClose',
    ];
    protected $clientService;

    public function boot(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function mount()
    {
        $this->cashRegisters = CashRegister::whereJsonContains('user_ids', Auth::id())->get();
        $this->currencies = Currency::all();
        $this->filteredCategories = TransactionCategory::where('type', '1')->get();
        $this->projects = Project::all();
        $this->allUsers = User::all();
        $this->selectedCashRegisterId = $this->cashRegisters->first()->id ?? null;
        $this->transaction_date = now()->toDateString();
        $this->startDate = now()->startOfMonth()->toDateString();
        $this->endDate = now()->endOfMonth()->toDateString();
        $this->clients = [];
        $this->searchTerm = request('search', '');
    }

    public function updatedType($value)
    {
        $this->filteredCategories = TransactionCategory::where('type', $value)->get();
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    public function openCashRegisterForm($cashRegisterId = null)
    {
        $this->resetForm();

        if ($cashRegisterId !== null) {
            $cashRegister = CashRegister::find($cashRegisterId);
            if ($cashRegister) {
                $this->selectedCashRegisterId = $cashRegister->id;
                $this->name = $cashRegister->name;
                $this->balance = $cashRegister->balance;
                $this->currency_id = $cashRegister->currency_id;
                $this->editCashRegisterUsers = $cashRegister->user_ids ?? [];
                $this->showCreateForm = true;
                $this->isDirty = false;
            }
        } else {
            $this->resetForm();
            $this->selectedCashRegisterId = null;
            $this->showCreateForm = true;
            $this->isDirty = false;
        }
    }

    public function closeCreateForm()
    {
        if ($this->isDirty) {
            $this->formBeingClosed = 'create';
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
            $this->showCreateForm = false;
        }
    }

    private function resetForm()
    {
        $this->transactionId = null;
        $this->type = null;
        $this->name = '';
        $this->balance = '';
        $this->currency_id = '';
        $this->amount = '';
        $this->note = '';
        $this->to_cash_register_id = '';
        $this->exchange_rate = '';
        $this->category_id = '';
        $this->transaction_date = now()->toDateString();
        $this->client_id = null;
        $this->clients = [];
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            switch ($this->formBeingClosed) {
                case 'create':
                    $this->resetForm();
                    $this->showCreateForm = false;
                    break;
                case 'transaction':
                    $this->resetForm();
                    $this->showTransactionForm = false;
                    break;
                case 'transfer':
                    $this->resetForm();
                    $this->showTransferForm = false;
                    break;
            }
            $this->isDirty = false;
        }
        $this->showConfirmationModal = false;
        $this->formBeingClosed = null;
    }


    public function openTransactionForm($transactionId = null)
    {
        $this->resetForm();
        if ($transactionId) {
            // Check if the transaction is referenced in orders
            $orders = Order::all();
            $isReferencedInOrders = $orders->contains(function ($order) use ($transactionId) {
                return in_array($transactionId, json_decode($order->transaction_ids, true) ?? []);
            });
            if ($isReferencedInOrders) {
                session()->flash('error', 'Невозможно редактировать транзакцию, связанную с заказом.');
                return;
            }

            $isTransfer = CashTransfer::where('from_transaction_id', $transactionId)
                ->orWhere('to_transaction_id', $transactionId)
                ->exists();

            if ($isTransfer) {
                session()->flash('error', 'Невозможно редактировать транзакцию, созданную как трансфер.');
                return;
            }

            $isSale = FinancialTransaction::where('id', $transactionId)
                ->where('note', 'like', '%Продажа товаров%')
                ->exists();

            if ($isSale) {
                session()->flash('error', 'Невозможно редактировать транзакцию, созданную как продажа.');
                return;
            }

            $transaction = FinancialTransaction::find($transactionId);

            if ($transaction) {
                $this->transactionId = $transaction->id;
                $this->type = $transaction->type;
                $this->amount = $transaction->amount;
                $this->note = $transaction->note;
                $this->category_id = $transaction->category_id;
                $this->transaction_date = $transaction->transaction_date;
                $this->client_id = $transaction->client_id;
                $this->selectedProjectId = $transaction->project_id;
                $this->selectedCashRegisterId = $transaction->cash_register_id;
                $this->currency_id = $transaction->currency_id;
                $this->showTransactionForm = true;
            }
        } else {
            $this->showTransactionForm = true;
        }
    }

    public function closeTransactionForm()
    {
        if ($this->isDirty) {
            $this->formBeingClosed = 'transaction';
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
            $this->showTransactionForm = false;
        }
    }

    public function handleSaveCashRegister()
    {
        $rules = [
            'name' => 'required|string|max:255',
            'editCashRegisterUsers' => 'required|array',
            'editCashRegisterUsers.*' => 'exists:users,id',
        ];

        if (!$this->selectedCashRegisterId) {
            $rules['balance'] = 'required|numeric';
            $rules['currency_id'] = 'required|exists:currencies,id';
        }

        $this->validate($rules);

        $this->editCashRegisterUsers = array_map('intval', $this->editCashRegisterUsers);

        if ($this->selectedCashRegisterId) {
            $cashRegister = CashRegister::find($this->selectedCashRegisterId);
            if ($cashRegister) {
                $cashRegister->name = $this->name;
                $cashRegister->user_ids = $this->editCashRegisterUsers;
                $cashRegister->save();

                session()->flash('message', 'Касса успешно обновлена.');
            }
        } else {
            CashRegister::create([
                'name' => $this->name,
                'balance' => $this->balance,
                'currency_id' => $this->currency_id,
                'user_ids' => $this->editCashRegisterUsers,
            ]);

            session()->flash('message', 'Касса успешно создана.');
        }

        $this->isDirty = false;
        $this->closeCreateForm();
        $this->refreshCashRegisters();
    }

    public function handleTransaction()
    {
        $this->validate([
            'amount' => 'required|numeric',
            'note' => 'nullable|string',
            'category_id' => 'nullable|exists:transaction_categories,id',
            'transaction_date' => 'required|date',
            'client_id' => 'nullable|exists:clients,id',
            'type' => 'required|in:1,0',
        ]);

        $cashRegister = CashRegister::find($this->selectedCashRegisterId);

        if (!$cashRegister) {
            session()->flash('error', 'Некорректная касса.');
            return;
        }

        if ($this->currency_id && $this->currency_id != $cashRegister->currency_id) {
            $transactionCurrency = Currency::find($this->currency_id);
            $cashRegisterCurrency = Currency::find($cashRegister->currency_id);
            $transactionExchangeRate = $transactionCurrency->currentExchangeRate()->exchange_rate;
            $cashRegisterExchangeRate = $cashRegisterCurrency->currentExchangeRate()->exchange_rate;
            $amountInDefaultCurrency = $this->amount / $transactionExchangeRate;
            $convertedAmount = $amountInDefaultCurrency * $cashRegisterExchangeRate;
        } else {
            $convertedAmount = $this->amount;
        }
        if ($this->transactionId) {
            $isTransfer = CashTransfer::where('from_transaction_id', $this->transactionId)
                ->orWhere('to_transaction_id', $this->transactionId)
                ->exists();

            if ($isTransfer) {
                session()->flash('error', 'Невозможно редактировать транзакцию, созданную как трансфер.');
                return;
            }

            $oldTransaction = FinancialTransaction::find($this->transactionId);
            if ($oldTransaction) {
                $oldType = $oldTransaction->type;
                $oldAmount = $oldTransaction->amount;
            }
        }

        FinancialTransaction::updateOrCreate(
            ['id' => $this->transactionId],
            [
                'type' => $this->type,
                'amount' => $convertedAmount,
                'cash_register_id' => $this->selectedCashRegisterId,
                'note' => $this->note,
                'transaction_date' => $this->transaction_date,
                'currency_id' => $cashRegister->currency_id,
                'category_id' => $this->category_id,
                'client_id' => $this->client_id,
                'project_id' => $this->selectedProjectId,
                'user_id' => Auth::id(),
            ]
        );

        if ($this->transactionId && isset($oldType) && isset($oldAmount)) {
            $balanceDifference = ($this->type == 1 ? $convertedAmount : -$convertedAmount) -
                ($oldType == 1 ? $oldAmount : -$oldAmount);
            $cashRegister->balance += $balanceDifference;
        } else {
            $cashRegister->balance += $this->type == 1 ? $convertedAmount : -$convertedAmount;
        }
        $cashRegister->save();

        session()->flash('message', $this->transactionId ? ($this->type == 1 ? 'Приход успешно обновлен.' : 'Расход успешно обновлен.') : ($this->type == 1 ? 'Приход успешно записан.' : 'Расход успешно записан.'));
        $this->isDirty = false;
        $this->closeTransactionForm();
        $this->refreshCashRegisters();
    }

    public function deleteTransaction()
    {
        $transaction = FinancialTransaction::find($this->transactionId);
        if ($transaction) {
            // Check if the transaction is referenced in orders
            $orders = Order::all();
            $isReferencedInOrders = $orders->contains(function ($order) use ($transaction) {
                return in_array($transaction->id, json_decode($order->transaction_ids, true) ?? []);
            });
            if ($isReferencedInOrders) {
                session()->flash('error', 'Невозможно удалить транзакцию, связанную с заказом.');
                return;
            }

            $isTransfer = CashTransfer::where('from_transaction_id', $this->transactionId)
                ->orWhere('to_transaction_id', $this->transactionId)
                ->exists();

            if ($isTransfer) {
                session()->flash('error', 'Невозможно удалить транзакцию, созданную как трансфер.');
                return;
            }

            $isSale = FinancialTransaction::where('id', $this->transactionId)
                ->where('note', 'like', '%Продажа товаров%')
                ->exists();

            if ($isSale) {
                session()->flash('error', 'Невозможно удалить транзакцию, созданную как продажа.');
                return;
            }

            $cashRegister = CashRegister::find($transaction->cash_register_id);
            $cashRegister->balance += $transaction->type == 1 ? -$transaction->amount : $transaction->amount;
            $cashRegister->save();
            $transaction->delete();
            session()->flash('message', $transaction->type == 1 ? 'Приход успешно удален.' : 'Расход успешно удален.');
            $this->closeTransactionForm();
            $this->refreshCashRegisters();
        }
    }

    public function handleSaveTransfer()
    {
        $this->validate([
            'amount' => 'required|numeric',
            'to_cash_register_id' => 'required|exists:cash_registers,id',
            'note' => 'nullable|string',
            'transaction_date' => 'required|date',
        ]);

        $fromCashRegister = CashRegister::find($this->selectedCashRegisterId);
        $toCashRegister = CashRegister::find($this->to_cash_register_id);

        if ($this->amount > $fromCashRegister->balance) {
            session()->flash('error', 'Недостаточно средств для перевода.');
            return;
        }

        $fromCurrency = Currency::find($fromCashRegister->currency_id);
        $toCurrency = Currency::find($toCashRegister->currency_id);
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
            'cash_register_id' => $this->selectedCashRegisterId,
            'note' => $this->note . ' ' . $transferNote,
            'transaction_date' => $this->transaction_date,
            'currency_id' => $fromCashRegister->currency_id,
            'user_id' => Auth::id(),
        ]);

        $toTransaction = FinancialTransaction::create([
            'type' => '1',
            'amount' => $amountInTargetCurrency,
            'cash_register_id' => $this->to_cash_register_id,
            'note' => $this->note . ' ' . $transferNote,
            'transaction_date' => $this->transaction_date,
            'currency_id' => $toCashRegister->currency_id,
            'user_id' => Auth::id(),
        ]);

        CashTransfer::create([
            'from_cash_register_id' => $this->selectedCashRegisterId,
            'to_cash_register_id' => $this->to_cash_register_id,
            'from_transaction_id' => $fromTransaction->id,
            'to_transaction_id' => $toTransaction->id,
            'user_id' => Auth::id(),
            'amount' => $this->amount,
            'note' => $this->note,
        ]);
        session()->flash('message', 'Трансфер успешно сохранен');
        $this->isDirty = false;
        $this->closeTransferForm();
        $this->refreshCashRegisters();
    }

    public function handleDeleteTransfer($transferId)
    {
        $transfer = CashTransfer::find($transferId);

        if ($transfer) {
            $fromTransaction = FinancialTransaction::find($transfer->from_transaction_id);
            $toTransaction = FinancialTransaction::find($transfer->to_transaction_id);
            $fromCashRegister = CashRegister::find($transfer->from_cash_register_id);
            $toCashRegister = CashRegister::find($transfer->to_cash_register_id);
            $fromCashRegister->balance += $fromTransaction->amount;
            $toCashRegister->balance -= $toTransaction->amount;
            $fromCashRegister->save();
            $toCashRegister->save();
            $fromTransaction->delete();
            $toTransaction->delete();
            $transfer->delete();

            session()->flash('message', 'Трансфер успешно удален.');
            $this->refreshCashRegisters();
        } else {
            session()->flash('error', 'Трансфер не найден.');
        }
    }

    public function deleteCashRegister($cashRegisterId)
    {
        $transactionExists = FinancialTransaction::where('cash_register_id', $cashRegisterId)->exists();
        if (!$transactionExists) {
            $cashRegister = CashRegister::find($cashRegisterId);
            if ($cashRegister) {
                $cashRegister->delete();
            }
            session()->flash('message', 'Касса успешно удалена.');
            $this->refreshCashRegisters();
        } else {
            session()->flash('error', 'Невозможно удалить кассу с транзакциями.');
        }
    }

    public function selectCashRegister($cashRegisterId)
    {
        $this->selectedCashRegisterId = $cashRegisterId;
    }

    public function updateDateFilter($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->refreshTransactions();
    }

    private function refreshTransactions()
    {
        $transactionsQuery = FinancialTransaction::where('cash_register_id', $this->selectedCashRegisterId);

        if ($this->startDate && $this->endDate) {
            $transactionsQuery->whereBetween('transaction_date', [$this->startDate, $this->endDate]);
        }

        $this->transactions = $transactionsQuery->with('user', 'currency')->get();

        // Calculate totals
        $this->totalIncome = $this->transactions->where('type', 1)->sum('amount');
        $this->totalExpense = $this->transactions->where('type', 0)->sum('amount');
    }

    public function updatedClientSearch()
    {
        $this->clientResults = $this->clientService->searchClients($this->clientSearch);
    }

    public function selectClient($clientId)
    {
        $this->selectedClient = $this->clientService->getClientById($clientId);
        $this->client_id = $clientId;
        $this->clientResults = [];
    }

    public function deselectClient()
    {
        $this->selectedClient = null;
        $this->client_id = null;
        $this->clientSearch = '';
        $this->clientResults = [];
    }

    public function showAllClients()
    {
        $this->clientResults = $this->clientService->getAllClients();
    }

    public function render()
    {
        $this->refreshTransactions();
        $this->clients = $this->clientService->searchClients($this->clientSearch);
        $transferTransactionIds = CashTransfer::pluck('from_transaction_id')
            ->merge(CashTransfer::pluck('to_transaction_id'))
            ->unique();

        return view('livewire.admin.finance.cash-register', [
            'cashRegisters' => $this->cashRegisters,
            'currencies' => $this->currencies,
            'transactions' => $this->transactions,
            'incomeCategories' => TransactionCategory::where('type', 1)->get(),
            'expenseCategories' => TransactionCategory::where('type', 0)->get(),
            'filteredCategories' => $this->filteredCategories,
            'allUsers' => $this->allUsers,
            'transferTransactionIds' => $transferTransactionIds,
            'projects' => $this->projects,
            'clientResults' => $this->clientResults,
            'selectedClient' => $this->selectedClient
        ]);
    }

    private function refreshCashRegisters()
    {

        $this->cashRegisters = CashRegister::whereJsonContains('user_ids', Auth::id())->get();
    }

    public function openTransferForm($fromCashRegisterId)
    {
        $this->resetForm();
        $this->selectedCashRegisterId = $fromCashRegisterId;
        $this->showTransferForm = true;
    }

    public function closeTransferForm()
    {
        if ($this->isDirty) {
            $this->formBeingClosed = 'transfer';
            $this->showConfirmationModal = true;
        } else {
            $this->resetForm();
            $this->showTransferForm = false;
        }
    }
}
