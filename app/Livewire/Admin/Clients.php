<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Client;
use App\Models\ClientsPhone;
use App\Models\Transaction;
use App\Models\ClientBalance;
use App\Models\WhReceipt;
use App\Models\Discount;
use App\Models\Project;
use App\Models\Order;
use App\Models\Sale;
use Carbon\Carbon;
use App\Models\Currency;


class Clients extends Component
{
    public $clients, $clientTypeFilter = 'all', $supplierFilter = 'all', $searchTerm;
    public $clientId, $first_name, $last_name, $client_type, $address, $contact_person, $note;
    public $status = false, $isSupplier = false, $isConflict = false;
    public $phones = [['number' => '', 'sms' => false]], $emails = [];
    public $discount_type = 'fixed', $discount_value = 0;
    public $showForm = false;
    public $clientBalance, $transactions = [], $clientProjects = [];
    protected $rules = [
        'first_name'       => 'required|string',
        'last_name'        => 'nullable|string',
        'contact_person'   => 'nullable|string',
        'client_type'      => 'required|string',
        'address'          => 'nullable|string',
        'phones.*.number'  => 'required|digits:8|distinct', 
        'emails.*'         => 'nullable|email|distinct',
        'note'             => 'nullable|string',
        'status'           => 'boolean',
        'discount_value'   => 'nullable|numeric|min:0',
        'discount_type'    => 'nullable|in:fixed,percent',
    ];

    public function mount()
    {
        $this->searchTerm = request('search', '');
        $this->loadClients();
    }

    public function render()
    {
        if ($this->clientId) {
            $this->loadClientProjects($this->clientId);
        }
        $this->loadClients();
        return view('livewire.admin.clients');
    }

    public function openForm($clientId = null)
    {
        $this->resetForm();
        $this->showForm = true;
        if ($clientId) {
            $this->loadClient($clientId);
        }
    }

    public function closeForm()
    {
        $this->resetForm();
        $this->showForm = false;
    }



    public function save()
    {
        $this->validate();
    
        if ($this->hasDuplicatePhoneNumbers()) {
            return;
        }
    
        $client = Client::updateOrCreate(
            ['id' => $this->clientId],
            $this->getClientData()
        );
    
        if (!ClientBalance::where('client_id', $client->id)->exists()) {
            ClientBalance::create([
                'client_id' => $client->id,
                'balance'   => 0,
            ]);
        }
    
        $this->savePhones($client);
        $this->saveEmails($client);
        $this->closeForm();
    }

    public function delete($id)
    {
        $client = Client::findOrFail($id);
        $client->phones()->delete();
        $client->emails()->delete();
        $client->delete();
    }

    public function edit($id)
    {
        $this->loadClient($id);
        $this->showForm = true;
    }

    public function addPhone()
    {
        $this->phones[] = ['number' => '', 'sms' => false];
    }

    public function addEmail()
    {
        $this->emails[] = '';
    }

    public function removePhone($index)
    {
        unset($this->phones[$index]);
        $this->phones = array_values($this->phones);
    }

    public function removeEmail($index)
    {
        unset($this->emails[$index]);
        $this->emails = array_values($this->emails);
    }

    public function resetForm()
    {
        $this->clientId = null;
        $this->first_name = '';
        $this->last_name = '';
        $this->client_type = '';
        $this->address = '';
        $this->contact_person = '';
        $this->note = '';
        $this->status = false;
        $this->isConflict = false;
        $this->isSupplier = false;
        $this->phones = [['number' => '', 'sms' => false]];
        $this->emails = [];
        $this->discount_type = 'fixed';
        $this->discount_value = 0;
    }

    public function loadClients()
    {
        $query = Client::query()->with(['phones', 'emails']);

        if ($this->clientTypeFilter !== 'all') {
            $query->where('client_type', $this->clientTypeFilter);
        }

        if ($this->supplierFilter !== 'all') {
            $query->where('is_supplier', $this->supplierFilter === 'supplier');
        }

        if (!empty($this->searchTerm)) {
            $query->where(function ($q) {
                $q->where('first_name', 'like', '%' . $this->searchTerm . '%')
                    ->orWhere('last_name', 'like', '%' . $this->searchTerm . '%')
                    ->orWhere('contact_person', 'like', '%' . $this->searchTerm . '%');
            });
        }

        $this->clients = $query->get();
    }

    public function updatedClientTypeFilter()
    {
        $this->loadClients();
    }

    public function updatedSupplierFilter()
    {
        $this->loadClients();
    }

    public function updatedSearchTerm()
    {
        if (strlen($this->searchTerm) > 0 && strlen($this->searchTerm) < 3) {
            session()->flash('error', 'Поиск должен содержать не менее 3 символов.');
        } else {
            $this->loadClients();
        }
    }

    private function loadClientProjects($clientId)
    {
        $projects = Project::where('client_id', $clientId)->get();
        $this->clientProjects = $projects->map(function ($project) {
            $transactions = Transaction::where('project_id', $project->id)->get();
            $income = $transactions->where('type', 1)->sum('amount');
            $expense = $transactions->where('type', 0)->sum('amount');
            $balance = $income - $expense;

            return [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status,
                'income' => $income,
                'expense' => $expense,
                'balance' => $balance,
            ];
        })->toArray();
    }

    private function loadClient($clientId)
    {
        $client = Client::with(['phones', 'emails'])->findOrFail($clientId);
        $this->clientId = $client->id;
        $this->first_name = $client->first_name;
        $this->last_name = $client->last_name;
        $this->client_type = $client->client_type;
        $this->address = $client->address;
        $this->contact_person = $client->contact_person;
        $this->note = $client->note;
        $this->status = (bool)$client->status;
        $this->isConflict = (bool)$client->is_conflict;
        $this->isSupplier = (bool)$client->is_supplier;
        $this->phones = $client->phones->map(fn($phone) => ['number' => $phone->phone, 'sms' => $phone->is_sms])->toArray();
        $this->emails = $client->emails->pluck('email')->toArray();
        $this->discount_type  = $client->discount_type;
        $this->discount_value = $client->discount;
        $this->clientBalance = ClientBalance::where('client_id', $client->id)->value('balance') ?? 0;
        $salesAndExpenses = Transaction::where('client_id', $client->id)
            ->with('currency')
            ->get()
            ->map(function ($transaction) {
                if (stripos($transaction->note, 'Продажа товаров') !== false) {
                    $transaction->event_type = 'Продажа';
                } elseif ($transaction->type === 1) {
                    $transaction->event_type = 'Приход';
                } elseif ($transaction->type === 0) {
                    $transaction->event_type = 'Расход';
                } else {
                    $transaction->event_type = 'Неизвестно';
                }
                return $transaction->toArray();
            })
            ->toArray();

        $receipts = WhReceipt::where('supplier_id', $client->id)
            ->get()
            ->map(function ($receipt) {
                $receipt->event_type = 'Оприходование';
                $receipt->amount = $receipt->amount ?? $receipt->total ?? 0;
                $receipt->note = $receipt->note ?? '';
                $receipt->date = $receipt->receipt_date ?? $receipt->created_at;
                return $receipt->toArray();
            })
            ->toArray();


        $salesFromCash = Sale::where('client_id', $client->id)
            ->whereNotNull('cash_id')
            ->get()
            ->map(function ($sale) {
                $saleArray = $sale->toArray();
                $saleArray['event_type'] = 'Продажа';
                $saleArray['amount'] = $sale->total_price;
                $saleArray['date'] = $sale->date ?? $sale->created_at;
                return $saleArray;
            })
            ->toArray();

        $this->transactions = array_merge($salesAndExpenses, $receipts, $salesFromCash);

        usort($this->transactions, function ($a, $b) {
            $aDate = isset($a['created_at']) ? strtotime($a['created_at']) : strtotime($a['date'] ?? '0');
            $bDate = isset($b['created_at']) ? strtotime($b['created_at']) : strtotime($b['date'] ?? '0');
            return $bDate - $aDate;
        });

        // $discount = Discount::where('client_id', $client->id)->first();
        // if ($discount) {
        //     $this->discount_type = $discount->discount_type;
        //     $this->discount_value = $discount->discount_value;
        // } else {
        //     $this->discount_type = 'fixed';
        //     $this->discount_value = 0;
        // }
    }

    private function hasDuplicatePhoneNumbers()
    {
        foreach ($this->phones as $index => $phone) {
            if (!empty($phone['number'])) {
                if (ClientsPhone::where('phone', $phone['number'])
                    ->when($this->clientId, fn($q) => $q->where('client_id', '<>', $this->clientId))
                    ->exists()
                ) {
                    $this->addError("phones.{$index}.number", 'Phone number already exists.');
                    return true;
                }
            }
        }
        return false;
    }

    private function getClientData()
    {
        return [
            'first_name'     => $this->first_name,
            'last_name'      => $this->last_name,
            'client_type'    => $this->client_type,
            'address'        => $this->address,
            'contact_person' => $this->contact_person,
            'note'           => $this->note,
            'status'         => $this->status,
            'is_conflict'    => $this->isConflict,
            'is_supplier'    => $this->isSupplier,
            'discount_type'  => $this->discount_type,
            'discount'       => $this->discount_value,
        ];
    }

    private function savePhones($client)
    {
        $client->phones()->delete();
        $phonesData = array_filter($this->phones, fn($phone) => !empty($phone['number']));
        if ($phonesData) {
            $client->phones()->createMany(
                array_map(fn($phone) => [
                    'phone'  => $phone['number'],
                    'is_sms' => $phone['sms'] ?? false,
                ], $phonesData)
            );
        }
    }

    private function saveEmails($client)
    {
        $client->emails()->delete();
        $emailsData = array_filter($this->emails);
        if ($emailsData) {
            $client->emails()->createMany(
                array_map(fn($email) => ['email' => $email], $emailsData)
            );
        }
    }

    // private function saveDiscount($client)
    // {
    //     if (!empty($this->discount_value) && $this->discount_value > 0) {
    //         Discount::updateOrCreate(
    //             ['client_id' => $client->id],
    //             [
    //                 'discount_type' => $this->discount_type,
    //                 'discount_value' => $this->discount_value,
    //             ]
    //         );
    //     }
    // }


    public function getFormattedTransactionsProperty()
    {
        $defaultCurrency = Currency::where('is_default', true)->first();
        $selectedCurrency = Currency::where('code', session('currency'))->first();
        if (!$selectedCurrency) {
            $selectedCurrency = Currency::where('is_default', true)->first();
        }

        return collect($this->transactions)->map(function ($transaction) use ($defaultCurrency, $selectedCurrency) {
            $date = $transaction['transaction_date'] ?? ($transaction['created_at'] ?? null);
            $dateFormatted = $date ? Carbon::parse($date)->format('d-m-Y') : '-';
            $typeStr = $transaction['event_type'] ?? 'Неизвестно';
            $amount = $transaction['amount'] ?? 0;

            if (in_array($typeStr, ['Продажа', 'Оприходование'])) {
                $origCurrency = $defaultCurrency;
            } else {

                if (isset($transaction['currency']) && isset($transaction['currency']['id'])) {
                    $origCurrency = Currency::find($transaction['currency']['id']);
                } else {
                    $origCurrency = $defaultCurrency;
                }
            }

            if ($origCurrency->id !== $selectedCurrency->id) {
                $amountConverted = $amount / $origCurrency->exchange_rate * $selectedCurrency->exchange_rate;
            } else {
                $amountConverted = $amount;
            }


            if ($typeStr === 'Продажа' || $typeStr === 'Приход') {
                $sign = $typeStr === 'Приход' ? '-' : '+';
                $amountFormatted = $sign . number_format($amountConverted, 2) . " " . $selectedCurrency->symbol;
                $amountClass = $typeStr === 'Приход' ? 'text-red-500' : 'text-green-500';
            } else {
                $amountFormatted = '-' . number_format($amountConverted, 2) . " " . $selectedCurrency->symbol;
                $amountClass = 'text-red-500';
            }

            return [
                'dateFormatted'   => $dateFormatted,
                'typeStr'         => $typeStr,
                'amount'          => $amountConverted,
                'amountFormatted' => $amountFormatted,
                'amountClass'     => $amountClass,
                'note'            => $transaction['note'] ?? '-'
            ];
        })->toArray();
    }
}
