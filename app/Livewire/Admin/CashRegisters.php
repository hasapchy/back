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
    public $cashId;
    public $showForm = false;
    public $showTransactionForm = false;
    public $showTransferForm = false;
    public $amount;
    public $note;
    public $to_cash_register_id;
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
    public $editCashRegisterUsers = [];
    public $allUsers = [];
    public $type;
    public $transactions;
    public $isDirty = false;
    public $projectId = null;
    public $projects = [];
    public $isSale = false;
    public $searchTerm;
    public $totalIncome = 0;
    public $totalExpense = 0;
    public $cashRegisters;
    public $transferTransactionIds;
    public $categories;

    protected $listeners = [
        'dateFilterUpdated' => 'updateDateFilter',
        // 'confirmClose',
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
        $this->projects = Project::all();
        $this->allUsers = User::all();
        $this->cashId = optional($this->cashRegisters->first())->id;
        $this->transaction_date = now()->toDateString();
        $this->clients = [];
        $this->categories = TransactionCategory::all();
        $this->transferTransactionIds = CashTransfer::pluck('from_transaction_id')
            ->merge(CashTransfer::pluck('to_transaction_id'))
            ->unique();
    }

    public function render()
    {
        $this->refreshTransactions();
        $this->clients = $this->clientService->searchClients($this->clientSearch);

        $transactions = $this->transactions->map(function ($transaction) {
            $transaction->isOrder = Order::all()->contains(function ($order) use ($transaction) {
                return in_array($transaction->id, json_decode($order->transaction_ids, true) ?? []);
            });
            $transaction->isTransfer = CashTransfer::where('from_transaction_id', $transaction->id)
                ->orWhere('to_transaction_id', $transaction->id)
                ->exists();
            $transaction->isSale = FinancialTransaction::where('id', $transaction->id)
                ->where('note', 'like', '%Продажа товаров%')
                ->exists();
            return $transaction;
        });

        return view('livewire.admin.finance.cash-register', [
            'incomeCategories' => $this->categories->where('type', 1),
            'expenseCategories' => $this->categories->where('type', 0),
            'transactions' => $transactions,
        ]);
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    public function openCashRegisterForm($cashRegisterId = null)
    {
        $this->resetForm();

        if ($cashRegisterId && $cashRegister = CashRegister::find($cashRegisterId)) {
            $this->cashId = $cashRegister->id;
            $this->name = $cashRegister->name;
            $this->balance = $cashRegister->balance;
            $this->currency_id = $cashRegister->currency_id;
            $this->editCashRegisterUsers = $cashRegister->user_ids ?? [];
        }

        $this->showForm = true;
    }

    public function closeCreateForm()
    {
            $this->resetForm();
            $this->showForm = false;
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
        $this->clients = [];
    }


    public function openTransferForm($fromCashRegisterId)
    {
        $this->resetForm();
        $this->cashId = $fromCashRegisterId;
        $this->showTransferForm = true;
    }

    public function closeTransferForm()
    {
            $this->resetForm();
            $this->showTransferForm = false;
    }

    public function openTransactionForm($transactionId = null)
    {
        $this->resetForm();
        if ($transactionId) {
            $transaction = FinancialTransaction::find($transactionId);
            if ($transaction) {
                $this->fill($transaction->toArray());
                $this->transactionId = $transaction->id;
                $this->showTransactionForm = true;
            }
        } else {
            $this->showTransactionForm = true;
        }
    }

    public function closeTransactionForm()
    {
            $this->resetForm();
            $this->showTransactionForm = false;
    }

    public function handleSaveCashRegister()
    {
        $rules = [
            'name' => 'required|string|max:255',
            'editCashRegisterUsers' => 'required|array',
            'editCashRegisterUsers.*' => 'exists:users,id',
        ];

        if (!$this->cashId) {
            $rules['balance'] = 'required|numeric';
            $rules['currency_id'] = 'required|exists:currencies,id';
        }

        $this->validate($rules);
        $this->editCashRegisterUsers = array_map('intval', $this->editCashRegisterUsers);

        if ($this->cashId) {
            $cashRegister = CashRegister::find($this->cashId);
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
        $this->closeCreateForm();
    }

    private function convertAmount($amount, $fromCurrencyId, $toCurrencyId)
    {
        if ($fromCurrencyId == $toCurrencyId) {
            return $amount;
        }
        $transactionCurrency = Currency::find($fromCurrencyId);
        $cashRegisterCurrency = Currency::find($toCurrencyId);
        $transactionExchangeRate = $transactionCurrency->currentExchangeRate()->exchange_rate;
        $cashRegisterExchangeRate = $cashRegisterCurrency->currentExchangeRate()->exchange_rate;
        $amountInDefaultCurrency = $amount / $transactionExchangeRate;
        return $amountInDefaultCurrency * $cashRegisterExchangeRate;
    }

    public function handleTransaction()
    {
        $this->validate([
            'amount'           => 'required|numeric',
            'note'             => 'nullable|string',
            'category_id'      => 'nullable|exists:transaction_categories,id',
            'transaction_date' => 'required|date',
            'client_id'        => 'nullable|exists:clients,id',
            'type'             => 'required|in:1,0',
        ]);

        $cashRegister = CashRegister::find($this->cashId);
        if (!$cashRegister) {
            session()->flash('error', 'Некорректная касса.');
            return;
        }

        $convertedAmount = isset($this->currency_id)
            ? $this->convertAmount($this->amount, $this->currency_id, $cashRegister->currency_id)
            : $this->amount;

        if (!$this->transactionId) {
            $this->note = sprintf(
                "Сумма: %s %s",
                number_format($this->amount, 2),
                Currency::find($this->currency_id)->currency_code ?? ''
            );
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
                $oldType   = $oldTransaction->type;
                $oldAmount = $oldTransaction->amount;
            }
        }

        FinancialTransaction::updateOrCreate(
            ['id' => $this->transactionId],
            [
                'type'             => $this->type,
                'amount'           => $convertedAmount,
                'cash_register_id' => $this->cashId,
                'note'             => $this->note,
                'transaction_date' => $this->transaction_date,
                'currency_id'      => $cashRegister->currency_id,
                'category_id'      => $this->category_id,
                'client_id'        => $this->client_id,
                'project_id'       => $this->projectId,
                'user_id'          => Auth::id(),
            ]
        );

        // Обновление баланса с использованием early return для упрощения логики
        if ($this->transactionId && isset($oldType) && isset($oldAmount)) {
            $balanceDifference = ($this->type == 1 ? $convertedAmount : -$convertedAmount) -
                                 ($oldType == 1 ? $oldAmount : -$oldAmount);
            $cashRegister->balance += $balanceDifference;
        } else {
            $cashRegister->balance += $this->type == 1 ? $convertedAmount : -$convertedAmount;
        }
        $cashRegister->save();

        session()->flash('message',
            $this->transactionId
                ? ($this->type == 1 ? 'Приход успешно обновлен.' : 'Расход успешно обновлен.')
                : ($this->type == 1 ? 'Приход успешно записан.' : 'Расход успешно записан.')
        );
        $this->closeTransactionForm();
    }

    public function deleteTransaction()
    {
        $transaction = FinancialTransaction::find($this->transactionId);
        if ($transaction) {
            $cashRegister = CashRegister::find($transaction->cash_register_id);
            $cashRegister->balance += $transaction->type == 1 ? -$transaction->amount : $transaction->amount;
            $cashRegister->save();
            $transaction->delete();
            session()->flash('message', $transaction->type == 1 ? 'Приход успешно удален.' : 'Расход успешно удален.');
            $this->closeTransactionForm();
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

        $fromCashRegister = CashRegister::find($this->cashId);
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
            'cash_register_id' => $this->cashId,
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
            'from_cash_register_id' => $this->cashId,
            'to_cash_register_id' => $this->to_cash_register_id,
            'from_transaction_id' => $fromTransaction->id,
            'to_transaction_id' => $toTransaction->id,
            'user_id' => Auth::id(),
            'amount' => $this->amount,
            'note' => $this->note,
        ]);
        session()->flash('message', 'Трансфер успешно сохранен');
        // $this->isDirty = false;
        $this->closeTransferForm();
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
        } else {
            session()->flash('error', 'Невозможно удалить кассу с транзакциями.');
        }
    }


    public function updateDateFilter($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->refreshTransactions();
    }

    private function refreshTransactions()
    {
        $transactionsQuery = FinancialTransaction::where('cash_register_id', $this->cashId);

        if ($this->startDate && $this->endDate) {
            $transactionsQuery->whereBetween('transaction_date', [$this->startDate, $this->endDate]);
        }

        $this->transactions = $transactionsQuery->with('user', 'currency')
            ->orderBy('transaction_date', 'desc')
            ->get();
        $this->totalIncome = $this->transactions->where('type', 1)->sum('amount');
        $this->totalExpense = $this->transactions->where('type', 0)->sum('amount');
    }

    //поиск клиента начало
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
    //поиск клиента конец
}
