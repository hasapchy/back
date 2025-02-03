<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Client;
use App\Models\ClientsPhone;
use App\Models\ClientsEmail;
use Illuminate\Support\Facades\Auth;
use App\Models\FinancialTransaction;
use App\Models\ClientBalance;
use App\Models\WarehouseProductReceipt;
use Illuminate\Support\Facades\DB;

class Clients extends Component
{
    public $clients;
    public $clientTypeFilter = 'all';
    public $supplierFilter = 'all';
    public $address;
    public $isSupplier = false;
    public $isConflict = false;
    public $contact_person;
    public $clientId;
    public $first_name;
    public $last_name;
    public $client_type;
    public $note;
    public $phones = [['number' => '', 'sms' => false]];
    public $emails = [];
    public $showForm = false;
    public $showConfirmationModal = false;
    protected $listeners = ['editClient'];
    public $columns = [
        'id',
        'first_name',
        'last_name',
        'client_type',
        'contact_person',
        'address',
        'note',
        'is_supplier',
        'is_conflict',
        'status',
    ];
    public $clientBalance;
    public $transactions = [];
    public $status = false;
    public $isDirty = false;
    public $searchTerm;

    public function mount()
    {
        $this->searchTerm = request('search', '');
        $this->loadClients();
    }

    public function openForm($clientId = null)
    {
        $this->resetForm();
        $this->showForm = true;
        if ($clientId) {
            $client = Client::with(['phones', 'emails'])->findOrFail($clientId);
            $this->clientId = $client->id;
            $this->first_name = $client->first_name;
            $this->last_name = $client->last_name;
            $this->client_type = $client->client_type;
            $this->address = $client->address;
            $this->contact_person = $client->contact_person;
            $this->note = $client->note;
            $this->status = (bool) $client->status;
            $this->isConflict = (bool) $client->is_conflict;
            $this->isSupplier = (bool) $client->is_supplier;
            $this->phones = $client->phones->map(function ($phone) {
                return [
                    'number' => $phone->phone,
                    'sms' => (bool) $phone->is_sms,
                ];
            })->toArray();
            $this->emails = $client->emails->pluck('email')->toArray();
            $this->clientBalance = ClientBalance::where('client_id', $clientId)->value('balance');
            $this->transactions = FinancialTransaction::where('client_id', $clientId)->get();
        }
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
            $this->isDirty = false;
            $this->showForm = false;
        }
        $this->showConfirmationModal = false;
    }

    public function updated()
    {

        $this->isDirty = true;
    }

    public function saveClient()
    {
        $validatedData = $this->validate([
            'first_name' => 'required|string',
            'last_name' => 'nullable|string',
            'contact_person' => 'nullable|string',
            'client_type' => 'required|string',
            'address' => 'nullable|string',
            'phones.*.number' => 'required|integer|distinct|min:6',
            'emails.*' => 'nullable|email|distinct',
            'note' => 'nullable|string',
            'status' => 'boolean', // Add this line
        ]);

        if (!Auth::user()->hasPermission('create_clients')) {
            $this->dispatch('error');
            return;
        }

        // Check for duplicate phone numbers, excluding the current client's numbers
        foreach ($this->phones as $phone) {
            if (!empty($phone['number'])) {
                $existingPhone = ClientsPhone::where('phone', $phone['number'])
                    ->where('client_id', '!=', $this->clientId)
                    ->exists();
                if ($existingPhone) {
                    $this->addError('phones.' . array_search($phone, $this->phones) . '.number', 'Phone number already exists.');
                    return;
                }
            }
        }

        $client = Client::updateOrCreate(
            ['id' => $this->clientId],
            [
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'address' => $this->address,
                'client_type' => $this->client_type,
                'is_conflict' => $this->isConflict,
                'is_supplier' => $this->isSupplier,
                'contact_person' => $this->contact_person,
                'note' => $this->note,
                'status' => $this->status, // Add this line
            ]
        );

        $client->phones()->delete();
        $client->emails()->delete();

        foreach ($this->phones as $phone) {
            if (!empty($phone['number'])) {
                ClientsPhone::create([
                    'client_id' => $client->id,
                    'phone' => $phone['number'],
                    'is_sms' => $phone['sms'] ?? false,
                ]);
            }
        }

        foreach ($this->emails as $email) {
            if (!empty($email)) {
                ClientsEmail::create([
                    'client_id' => $client->id,
                    'email' => $email,
                ]);
            }
        }

        $this->resetForm();
        $this->clients = Client::with(['phones', 'emails'])->get();
        $this->dispatch('created');
        $this->dispatch('refreshPage');
    }

    public function deleteClient($id)
    {
        if (!Auth::user()->hasPermission('delete_clients')) {
            $this->dispatch('error');
            return;
        }

        $client = Client::findOrFail($id);
        $client->phones()->delete();
        $client->emails()->delete();
        $client->delete();

        $this->clients = Client::with(['phones', 'emails'])->get();
        $this->dispatch('deleted');
        $this->dispatch('refreshPage');
    }


    public function editClient($id)
    {
        if (!Auth::user()->hasPermission('edit_clients')) {
            $this->dispatch('error');
            session()->flash('message', 'У вас нет прав для редактирования клиентов.');
            session()->flash('type', 'error');
            return;
        }

        $client = Client::with(['phones', 'emails'])->findOrFail($id);


        $this->clientId = $client->id;
        $this->first_name = $client->first_name;
        $this->last_name = $client->last_name;
        $this->client_type = $client->client_type;
        $this->address = $client->address;
        $this->isConflict = (bool) $client->is_conflict;
        $this->isSupplier = (bool) $client->is_supplier;
        $this->contact_person = $client->contact_person;
        $this->note = $client->note;
        $this->status = (bool) $client->status;

        $this->phones = $client->phones->map(function ($phone) {
            return [
                'number' => $phone->phone,
                'sms' => (bool) $phone->is_sms,
            ];
        })->toArray();

        $this->emails = $client->emails->pluck('email')->toArray();

        $this->clientBalance = ClientBalance::where('client_id', $client->id)->value('balance');
        $this->transactions = FinancialTransaction::where('client_id', $client->id)
            ->select('transaction_date', 'type as event_type', DB::raw('amount as amount'), 'note')
            ->get()
            ->toArray();

        $stockReceptions = WarehouseProductReceipt::where('supplier_id', $client->id)
            ->select('created_at as transaction_date', DB::raw("'Оприходование' as event_type"), 'converted_total as amount', 'note')
            ->get()
            ->toArray();

        $this->transactions = array_merge($this->transactions, $stockReceptions);

        usort($this->transactions, function ($a, $b) {
            return strtotime($b['transaction_date']) - strtotime($a['transaction_date']);
        });

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
        $this->isSupplier = false;
        $this->isConflict = false;
        $this->last_name = '';
        $this->address = '';
        $this->client_type = '';
        $this->phones = [['number' => '', 'sms' => false]];
        $this->emails = [];
        $this->showForm = false;
        $this->contact_person = '';
        $this->note = '';
        $this->status = false;
        $this->showForm = false;
    }


    public function loadClients()
    {
        $query = Client::query()->with(['phones', 'emails']);

        if ($this->clientTypeFilter !== 'all') {
            $query->where('client_type', $this->clientTypeFilter);
        }

        if ($this->supplierFilter === 'suppliers') {
            $query->where('is_supplier', true);
        } elseif ($this->supplierFilter === 'clients') {
            $query->where('is_supplier', false);
        }

        if (!empty($this->searchTerm)) {
            $query->where('first_name', 'like', '%' . $this->searchTerm . '%')
                ->orWhere('last_name', 'like', '%' . $this->searchTerm . '%')
                ->orWhere('contact_person', 'like', '%' . $this->searchTerm . '%')
                ->orWhereHas('phones', function ($q) {
                    $q->where('phone', 'like', '%' . $this->searchTerm . '%');
                });
        }
        $this->clients = $query->get();
    }

    public function updatedSearchTerm()
    {
        if (strlen($this->searchTerm) < 3 && strlen($this->searchTerm) > 0) {
            $this->loadClients();
            session()->flash('error', 'Поиск должен содержать не менее 3 символов.');
        } else {
            session()->forget('error');
        }
    }


    public function filterClients($type)
    {
        if (in_array($type, ['individual', 'company', 'all'])) {
            $this->clientTypeFilter = $type;
        }

        if (in_array($type, ['suppliers', 'clients', 'all'])) {
            $this->supplierFilter = $type;
        }
        $this->loadClients();
        $this->updateColumns();
    }

    public function updateColumns()
    {
        if ($this->clientTypeFilter === 'individual') {
            $this->columns = [
                'id',
                'first_name',
                'last_name',
                'contact_person',
                'address',
                'note',
            ];
        } elseif ($this->clientTypeFilter === 'company') {
            $this->columns = [
                'id',
                'first_name',
                'contact_person',
                'address',
                'is_supplier',
                'note',
            ];
        } else {
            $this->columns = [
                'id',
                'first_name',
                'last_name',
                'client_type',
                'contact_person',
                'address',
                'note',
                'is_supplier',
                'is_conflict',
                'status',
            ];
        }
    }

    public function render()
    {
        return view('livewire.admin.clients');
    }
}
