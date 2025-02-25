<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\CashRegister;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\TransactionCategory;
use App\Models\CashTransfer;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use App\Models\Order;
use App\Services\ClientService;
use App\Services\CurrencyConverter;

class Transactions extends Component
{
    public $cashId;
    public $showForm = false;
    public $amount;
    public $note;
    public $currencies;
    public $exchange_rate;
    public $category_id;
    public $date;
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
    public $orig_amount;
    public $orig_currency_id;
    public $readOnly = false;
    public $filters = [];


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
        $this->date = now()->format('Y-m-d H:i:s');
        $this->clients = [];
        $this->categories = TransactionCategory::all();
        $this->transferTransactionIds = CashTransfer::pluck('tr_id_from')
            ->merge(CashTransfer::pluck('tr_id_to'))
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
            $transaction->isTransfer = CashTransfer::where('tr_id_from', $transaction->id)
                ->orWhere('tr_id_to', $transaction->id)
                ->exists();
            $transaction->isSale = Transaction::where('id', $transaction->id)
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

        return view('livewire.admin.finance.transactions', [
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
        $this->reset(['transactionId', 'projectId', 'note', 'selectedClient', 'category_id', 'type', 'orig_amount', ]);
        $this->date = now()->format('Y-m-d\TH:i');
    }

    public function openForm($transactionId = null)
    {
        $this->resetForm();
        $this->readOnly = false; // по умолчанию форма редактируемая

        if ($transactionId && ($transaction = Transaction::find($transactionId))) {
            // Проверяем, является ли транзакция трансфером или продажей
            $isTransfer = \App\Models\CashTransfer::where('tr_id_from', $transaction->id)
                ->orWhere('tr_id_to', $transaction->id)
                ->exists();
            $isSale = Transaction::where('id', $transaction->id)
                ->where('note', 'like', '%Продажа%')
                ->exists();

            if ($isTransfer || $isSale) {
                // Устанавливаем режим только для просмотра
                $this->readOnly = true;
                session()->flash('message', 'Транзакция трансфера или продажи доступна для просмотра, редактирование отключено.');
            }

            $this->fill($transaction->toArray());
            $this->date = \Carbon\Carbon::parse($transaction->date)->format('Y-m-d\TH:i');
            // Явно задаём категорию и тип транзакции
            $this->category_id = $transaction->category_id;
            $this->type = $transaction->type;

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
            'orig_amount'        => 'required|numeric',
            'note'               => 'nullable|string',
            'category_id'        => 'nullable|exists:transaction_categories,id',
            'date'               => 'required|date',
            'client_id'          => 'nullable|exists:clients,id',
            'type'               => 'required|in:1,0',
            'projectId'          => 'nullable|exists:projects,id',
        ]);

        $cashRegister = $this->cashRegisters->firstWhere('id', $this->cashId);
        if (!$cashRegister) {
            session()->flash('message', 'Некорректная касса.');
            return;
        }

        // Если создаём новую транзакцию, присваиваем текущий timestamp
        if (!$this->transactionId) {
            $dateToSave = now()->format('Y-m-d H:i:s');
        } else {
            // При редактировании сохраняем выбранную дату с добавлением времени (она может быть изменена вручную, если требуется)
            $dateToSave = \Carbon\Carbon::parse($this->date)->format('Y-m-d H:i:s');
        }

        // Используем оригинальные данные из формы
        $originalAmount = $this->orig_amount;
        $fromCurrency = Currency::find($cashRegister->currency_id);
        $toCurrency   = Currency::find($cashRegister->currency_id);

        // Конвертируем введённую оригинальную сумму в валюту кассы
        $convertedAmount = $fromCurrency
            ? CurrencyConverter::convert($originalAmount, $fromCurrency, $toCurrency)
            : $originalAmount;

        $oldTransaction = Transaction::find($this->transactionId);

        if ($oldTransaction) {
            // Отменяем старый эффект
            if ($oldTransaction->type == 1) {
                $cashRegister->balance -= $oldTransaction->amount;
            } else {
                $cashRegister->balance += $oldTransaction->amount;
            }
        }

        // Обновляем запись, сохраняем итоговую сумму и оригинальные данные
        $data = [
            'type'             => $this->type,
            'amount'           => $convertedAmount,
            'cash_id'          => $this->cashId,
            'note'             => $this->note,
            'date'             => $dateToSave,
            'currency_id'      => $toCurrency->id,
            'category_id'      => $this->category_id,
            'client_id'        => $this->client_id,
            'project_id'       => $this->projectId,
            'user_id'          => Auth::id(),
            'orig_amount'      => $originalAmount,
        ];

        $transaction = Transaction::updateOrCreate(
            ['id' => $this->transactionId],
            $data
        );

        // Применяем эффект транзакции на баланс кассы
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
        $transaction = Transaction::find($this->transactionId);
        if ($transaction) {
            // Проверяем, является ли транзакция трансфером или продажей
            $isTransfer = \App\Models\CashTransfer::where('tr_id_from', $transaction->id)
                ->orWhere('tr_id_to', $transaction->id)
                ->exists();
            $isSale = Transaction::where('id', $transaction->id)
                ->where('note', 'like', '%Продажа%')
                ->exists();

            if ($isTransfer || $isSale) {
                session()->flash('message', 'Нельзя удалить транзакцию трансфера или продажи.');
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
        $transactionsQuery = Transaction::where('cash_id', $this->cashId);

        if ($this->startDate && $this->endDate) {
            $transactionsQuery->whereBetween('date', [$this->startDate, $this->endDate]);
        }
        $this->transactions = $transactionsQuery->with('user', 'currency')
            ->orderBy('date', 'desc')
            ->get();


        $this->transactions = $this->transactions->map(function ($transaction) {
            $transaction->isOrder = Order::all()->contains(function ($order) use ($transaction) {
                return in_array($transaction->id, json_decode($order->transaction_ids, true) ?? []);
            });
            $transaction->isSale = Transaction::where('id', $transaction->id)
                ->where('note', 'like', '%Продажа%')
                ->exists();
            return $transaction;
        });


        if (!empty($this->filters)) {

            if (in_array('all', $this->filters)) {
                $selectedFilters = ['orders', 'sales', 'projects', 'normal'];
            } else {
                $selectedFilters = $this->filters;
            }
            $this->transactions = $this->transactions->filter(function ($transaction) use ($selectedFilters) {
                $match = false;

                if (in_array('orders', $selectedFilters) && $transaction->isOrder) {
                    $match = true;
                }

                if (in_array('sales', $selectedFilters) && $transaction->isSale) {
                    $match = true;
                }

                if (in_array('projects', $selectedFilters) && !empty($transaction->project_id)) {
                    $match = true;
                }

                if (
                    in_array('normal', $selectedFilters) &&
                    !$transaction->isSale &&
                    !$transaction->isOrder &&
                    empty($transaction->project_id)
                ) {
                    $match = true;
                }
                return $match;
            })->values();
        }

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
