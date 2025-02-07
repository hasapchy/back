<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Project;
use App\Models\User;
use App\Models\FinancialTransaction;
use Illuminate\Support\Facades\Auth;
use App\Services\ClientService;

class Projects extends Component
{
    public $projects, $projectTransactions, $totalAmount = 0;
    public $name, $users = [], $projectId;
    public $showForm = false, $isDirty = false, $showConfirmationModal = false, $allUsers;
    public $clientResults = [], $clientSearch, $selectedClient, $clientId;
    public $searchTerm, $startDate, $endDate;
    protected $clientService;
    protected $rules = [
        'name' => 'required|string|max:255',
        'start_date' => 'nullable|date',
        'end_date'   => 'nullable|date',
        'users'      => 'nullable|array',
        'clientId'   => 'required|integer',
    ];

    protected $listeners = [
        'dateFilterUpdated' => 'updateDateFilter',
    ];

    public function boot(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function mount()
    {
        $this->searchTerm = request('search', '');
        $this->allUsers = User::all();
        $this->load();
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
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

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    public function render()
    {
        return view('livewire.admin.projects');
    }

    public function save()
    {
        $this->validate();

        Project::updateOrCreate(
            ['id' => $this->projectId],
            [
                'name' => $this->name,
                'user_id' => Auth::id(),
                'users' => $this->users,
                'client_id' => $this->clientId,
            ]
        );

        session()->flash('message', 'Проект успешно сохранен.');
        $this->resetForm();
        $this->showForm = false;
        $this->isDirty = false;
        $this->load();
    }

    public function edit($id)
    {
        $project = Project::find($id);
        $this->projectId = $project->id;
        $this->name = $project->name;
        $this->users = $project->users;
        $this->selectedClient = $project->client;
        $this->showForm = true;
        $this->isDirty = false;
        $this->loadTransactions();
    }

    // public function confirmDeleteProject($projectId)
    // {
    //     $this->projectId = $projectId;
    //     $this->showDeleteConfirmationModal = true;
    // }

    public function delete($id)
    {
        if (FinancialTransaction::where('project_id', $id)->exists()) {
            session()->flash('error', 'Невозможно удалить проект, так как к нему привязаны транзакции.');
            return;
        }
        Project::destroy($id);
        session()->flash('message', 'Проект успешно удален.');
        $this->closeForm();
    }

    private function filterByDates($query)
    {
        if ($this->startDate && $this->endDate) {
            $query->whereBetween('created_at', [$this->startDate, $this->endDate]);
        }
        return $query;
    }

    private function applySearch($query)
    {
        if ($this->searchTerm && strlen($this->searchTerm) >= 3) {
            $query->where('name', 'like', '%' . $this->searchTerm . '%');
        }
        return $query;
    }

    public function load()
    {
        $query = Project::orderBy('created_at', 'desc');
        $query = $this->filterByDates($query);
        $query = $this->applySearch($query);
        $this->projects = $query->get();
    }

    public function updateDateFilter($startDate, $endDate)
    {
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->load();
    }

    public function updatedSearchTerm()
    {
        if (strlen($this->searchTerm) < 3 && strlen($this->searchTerm) > 0) {
            session()->flash('error', 'Поиск должен содержать не менее 3 символов.');
        }
        $this->load();
        session()->forget('error');
    }

    private function resetForm()
    {
        $this->projectId   = null;
        $this->name        = '';
        $this->users       = [];
        $this->selectedClient    = null;
        $this->projectTransactions = [];
        $this->totalAmount = 0;
    }

    //начало поиск клиент
    public function updatedClientSearch()
    {
        $this->clientResults = $this->clientService->searchClients($this->clientSearch);
    }

    public function showAllClients()
    {
        $this->clientResults = $this->clientService->getAllClients();
    }

    public function selectClient($clientId)
    {
        $this->selectedClient = $this->clientService->getClientById($clientId);
        $this->clientId = $clientId;
        $this->clientResults = [];
    }

    public function deselectClient()
    {
        $this->selectedClient = null;
        $this->clientId = null;
        $this->clientSearch = '';
        $this->clientResults = [];
    }

    //конец поиск клиент
    private function loadTransactions()
    {
        $this->projectTransactions = FinancialTransaction::where('project_id', $this->projectId)->get();
        $this->totalAmount = $this->projectTransactions->sum(
            fn($t) => $t->type == 1 ? $t->amount : -$t->amount
        );
    }
}
