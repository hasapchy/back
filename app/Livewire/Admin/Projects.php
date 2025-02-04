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
    public $projects;
    public $name;
    public $showConfirmationModal = false;
    public $start_date;
    public $end_date;
    public $user_id;
    public $users = [];
    public $projectId;
    public $showForm = false;
    public $allUsers;
    public $isDirty = false;
    public $projectTransactions = [];
    public $totalAmount = 0;
    public $searchTerm;
    public $clientResults = [];
    public $clientSearch;
    public $selectedClient;
    public $clientId;
    public $startDate; //это для фильтра
    public $endDate; //это для фильтра
    protected $clientService;
    protected $rules = [
        'name' => 'required|string|max:255',
        'start_date' => 'required|date',
        'end_date' => 'nullable|date|after_or_equal:start_date',
        'users' => 'nullable|array',
        'clientId' => 'nullable|integer',
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

    public function saveProject()
    {
        $this->validate();

        Project::updateOrCreate(
            ['id' => $this->projectId],
            [
                'name' => $this->name,
                'start_date' => $this->start_date,
                'end_date' => $this->end_date,
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

    public function selectProject($projectId)
    {
        $project = Project::find($projectId);

        if ($project) {
            $this->projectId = $project->id;
            $this->name = $project->name;
            $this->start_date = $project->start_date;
            $this->end_date = $project->end_date;
            $this->users = $project->users;
            $this->clientId = $project->client_id; // Add this line
            $this->showForm = true;
            $this->isDirty = false;
            $this->loadTransactions();
        } else {
            session()->flash('error', 'Проект не найден.');
        }
    }

    // public function confirmDeleteProject($projectId)
    // {
    //     $this->projectId = $projectId;
    //     $this->showDeleteConfirmationModal = true;
    // }

    public function deleteProject($projectId)
    {
        $transactionCount = FinancialTransaction::where('project_id', $projectId)->count();

        if ($transactionCount > 0) {
            session()->flash('error', 'Невозможно удалить проект, так как к нему привязаны транзакции.');
            // $this->showDeleteConfirmationModal = false;
            return;
        }

        Project::destroy($projectId);
        session()->flash('message', 'Проект успешно удален.');
        // $this->showDeleteConfirmationModal = false;
        $this->load();
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
        $query = Project::query()->orderBy('created_at', 'desc');
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
        $this->projectId = null;
        $this->name = '';
        $this->start_date = '';
        $this->end_date = '';
        $this->users = [];
        $this->projectTransactions = [];
        $this->totalAmount = 0;
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
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
        $this->totalAmount = $this->projectTransactions->sum(function ($transaction) {
            return $transaction->type == 1 ? $transaction->amount : -$transaction->amount;
        });
    }

    public function render()
    {
        return view('livewire.admin.projects');
    }
}
