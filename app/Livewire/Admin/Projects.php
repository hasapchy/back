<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Project;
use App\Models\User;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use App\Services\ClientService;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;

class Projects extends Component
{
    use WithFileUploads;
    public $projects, $projectTransactions, $totalAmount = 0;
    public $name, $users = [], $projectId;
    public $showForm = false, $isDirty = false, $showConfirmationModal = false, $allUsers;
    public $clientResults = [], $clientSearch, $selectedClient, $clientId;
    public $searchTerm, $startDate, $endDate;
    public $fileAttachments = [];
    public $attachments = [];
    public $budget;
    protected $clientService;
    protected $rules = [
        'name' => 'required|string|max:255',

        'users'      => 'nullable|array',
        'clientId'   => 'required|integer',
        'budget'            => 'nullable|numeric',
        'fileAttachments.*' => 'file|mimes:jpeg,png,pdf,doc,docx,xls,xlsx|max:2048',
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

        $project = Project::updateOrCreate(
            ['id' => $this->projectId],
            [
                'name' => $this->name,
                'user_id' => Auth::id(),
                'users' => $this->users,
                'client_id' => $this->clientId,
                'budget'    => $this->budget,
            ]
        );
        if ($this->fileAttachments) {
            $filePaths = [];
            foreach ($this->fileAttachments as $file) {
                $path = $file->store('project_files', 'public');
                $filePaths[] = [
                    'file_path' => $path,
                    'file_name' => $file->getClientOriginalName(),
                ];
            }
            // Сохраняем пути в поле files проекта (предварительно настройте миграцию)
            $project->update(['files' => json_encode($filePaths)]);
        }

        session()->flash('message', 'Проект успешно сохранен.');
        $this->resetForm();
        $this->showForm = false;
        $this->isDirty = false;
        $this->load();
    }

    public function edit($id)
    {
        $project = Project::find($id);
        $this->projectId     = $project->id;
        $this->name          = $project->name;
        $this->users         = $project->users;
        $this->selectedClient = $project->client;
        $this->clientId      = $project->client_id; 
        $this->showForm      = true;
        $this->isDirty       = false;
        $this->budget        = $project->budget;
        $this->attachments   = $project->files ? json_decode($project->files, true) : [];
        $this->loadTransactions();
    }

    public function delete($id)
    {
        if (Transaction::where('project_id', $id)->exists()) {
            session()->flash('error', 'Невозможно удалить проект, так как к нему привязаны транзакции.');
            return;
        }
        Project::destroy($id);
        session()->flash('message', 'Проект успешно удален.');
        $this->closeForm();
    }

    public function removeFile($index)
    {
        if (!isset($this->attachments[$index])) {
            return;
        }
        $file = $this->attachments[$index];
        Storage::disk('public')->delete($file['file_path']);
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
        if ($this->projectId) {
            $project = Project::find($this->projectId);
            $project->update(['files' => json_encode($this->attachments)]);
        }
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
        $this->selectedClient = null;
        $this->projectTransactions = [];
        $this->totalAmount = 0;
        $this->budget      = null;
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
        $this->projectTransactions = Transaction::where('project_id', $this->projectId)->get();
        $this->totalAmount = $this->projectTransactions->sum(
            fn($t) => $t->type == 1 ? $t->amount : -$t->amount
        );
    }
}
