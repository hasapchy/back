<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\FinancialTransaction;
use App\Models\TransactionCategory;
use App\Models\CashTransfer;
use App\Models\Project;
use App\Models\ClientBalance;
use Illuminate\Support\Facades\Auth;
use App\Models\Order;
use App\Services\ClientService;
use App\Services\CurrencyConverter;

class Finances extends Component
{
    public $cashId;
    public $showForm = false;
    public $amount;
    public $note;
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
    public $transactions;
    public $isDirty = false;
    public $projectId;
    public $projects = [];
    public $searchTerm;
    public $totalIncome = 0;
    public $totalExpense = 0;
    public $cashRegisters;
    public $transferTransactionIds;
    public $categories;
    public $type;
    public $currency_id;

    protected $listeners = [
        'dateFilterUpdated' => 'updateDateFilter',
    ];
    protected $clientService;

    public function boot(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function mount()
    {
        $this->cashRegisters = CashRegister::whereJsonContains('users', (string) Auth::id())->get();
        $this->currencies = Currency::all();
        $this->projects = Project::whereJsonContains('users', (string) Auth::id())->get();
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
        $this->updatedClientId();
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
                ->where('note', 'like', '%Продажа%')
                ->exists();
            return $transaction;
        });

        $currentCashRegister = CashRegister::find($this->cashId);
        $currentBalance = $currentCashRegister ? $currentCashRegister->balance : 0;
        $dayBalance = null;
        if ($this->startDate && $this->endDate && $this->startDate === $this->endDate) {
            $dayBalance = $this->transactions->reduce(function ($carry, $transaction) {
                return $carry + ($transaction->type == 1 ? $transaction->amount : -$transaction->amount);
            }, 0);
        }

        return view('livewire.admin.finance.finances', [
            'incomeCategories'  => $this->categories->where('type', 1),
            'expenseCategories' => $this->categories->where('type', 0),
            'transactions'      => $transactions,
            'currentBalance'    => $currentBalance,
            'dayBalance'        => $dayBalance,
        ]);
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    private function resetForm()
    {
        $this->reset('transactionId', 'projectId', 'amount', 'note', 'exchange_rate', 'selectedClient', 'category_id', 'type', 'currency_id');
        $this->transaction_date = now()->toDateString();
    }

    public function openForm($transactionId = null)
    {
        $this->resetForm();

        if ($transactionId && ($transaction = FinancialTransaction::find($transactionId))) {
            $this->fill($transaction->toArray());
            if ($transaction->client_id) {
                $this->client_id = $transaction->client_id;
                $this->selectedClient = $this->clientService->getClientById($transaction->client_id);
            }
            $this->transactionId = $transaction->id;
        }

        $this->showForm = true;
    }

    public function closeForm()
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save()
    {
        $this->validate([
            'amount'           => 'required|numeric',
            'note'             => 'nullable|string',
            'category_id'      => 'nullable|exists:transaction_categories,id',
            'transaction_date' => 'required|date',
            'client_id'        => 'nullable|exists:clients,id',
            'type'             => 'required|in:1,0',
            'projectId'        => 'nullable|exists:projects,id',
        ]);

        $cashRegister = $this->cashRegisters->firstWhere('id', $this->cashId);
        if (!$cashRegister) {
            session()->flash('error', 'Некорректная касса.');
            return;
        }

        $fromCurrency = $this->currency_id ? Currency::find($this->currency_id) : null;
        $toCurrency   = Currency::find($cashRegister->currency_id);
        $convertedAmount = $fromCurrency
            ? CurrencyConverter::convert($this->amount, $fromCurrency, $toCurrency)
            : $this->amount;

        if (!$this->transactionId) {
            $code = $fromCurrency ? $fromCurrency->code : '';
            $initialNote = sprintf("(Изначальная сумма: %s %s)", number_format($this->amount, 2), $code);
            $this->note = trim($this->note)
                ? $this->note . "\n" . $initialNote
                : $initialNote;
        }

        $oldTransaction = FinancialTransaction::find($this->transactionId);

        if ($oldTransaction) {
            if ($oldTransaction->type == 1) {
                $cashRegister->balance += $oldTransaction->amount;
            } else {
                $cashRegister->balance -= $oldTransaction->amount;
            }
        }

        $transaction = FinancialTransaction::updateOrCreate(
            ['id' => $this->transactionId],
            [
                'type'             => $this->type,
                'amount'           => $convertedAmount,
                'cash_register_id' => $this->cashId,
                'note'             => $this->note,
                'transaction_date' => $this->transaction_date,
                'currency_id'      => $toCurrency->id,
                'category_id'      => $this->category_id,
                'client_id'        => $this->client_id,
                'project_id'       => $this->projectId,
                'user_id'          => Auth::id(),
            ]
        );

        if ($this->type == 0) {
            $cashRegister->balance -= $convertedAmount;
        } else {
            $cashRegister->balance += $convertedAmount;
        }
        $cashRegister->save();

        session()->flash(
            'message',
            $oldTransaction
                ? ($this->type == 1 ? 'Приход успешно обновлен.' : 'Расход успешно обновлен.')
                : ($this->type == 1 ? 'Приход успешно записан.' : 'Расход успешно записан.')
        );
        $this->closeForm();
    }

    public function delete()
    {
        $transaction = FinancialTransaction::find($this->transactionId);
        if ($transaction) {
            if ($transaction->isSale) {
                session()->flash('error', 'Нельзя удалить транзакцию продажи.');
                return;
            }
            $cashRegister = $this->cashRegisters->firstWhere('id', $this->cashId);
            if ($transaction->type == 0) {
                $cashRegister->balance += $transaction->amount;
            } else {
                $cashRegister->balance -= $transaction->amount;
            }
            $cashRegister->save();
            $transaction->delete();
            session()->flash(
                'message',
                $transaction->type == 1 ? 'Приход успешно удален.' : 'Расход успешно удален.'
            );
            $this->closeForm();
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
            ->orderBy('id', 'desc')
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

    public function updatedClientId()
    {
        $this->projects = Project::whereJsonContains('users', (string) Auth::id())
            ->where('client_id', $this->client_id)
            ->get();
    }
}
