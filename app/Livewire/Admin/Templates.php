<?php

namespace App\Livewire\Admin;

use Livewire\Component;
use App\Models\Template;
use App\Models\Currency;
use App\Models\Project;
use Illuminate\Support\Facades\Auth;
use App\Models\TransactionCategory;
use App\Models\Client;
use App\Models\CashRegister;
use App\Models\Transaction;
use Livewire\Attributes\Lazy;
use Illuminate\Support\Facades\Cache;
use App\Services\ClientService;


// #[Lazy]
class Templates extends Component
{
    public $templateName;
    public $templateIcon;
    public $templates;
    public $templateId;
    public $showForm = false;
    public $templateAmount;
    public $type = '1';
    public $categoryId;
    public $templateTransactionDate;
    public $templateNote;
    public $client_id;
    public $filteredCategories = [];
    public $client_search = '';
    public $templateClients = [];
    public $currencies = [];
    public $cashRegisters = [];
    public $cashId = null;
    public $showTForm = false;
    public $applyTemplateId;
    public $showConfirmationModal = false;
    public $projectId = null;
    public $projects = [];
    public $isDirty = false;
    public $clientSearch = ''; 
    public $clientResults = [];
    public $selectedClient; 
    public $clients = [];
    protected $listeners = ['confirmClose'];
    protected $clientService;

    public function boot(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function mount()
    {
        $this->loadTemplates();
        $this->filteredCategories = TransactionCategory::where('type', '1')->get();
        $this->currencies = Currency::all();
        $this->cashRegisters = Auth::user()->is_admin
            ? CashRegister::all()
            : CashRegister::whereJsonContains('users', (string) Auth::id())->get();
        $this->projects = Auth::user()->is_admin
            ? Project::all()
            : Project::whereJsonContains('users', (string) Auth::id())->get();
    }

    public function render()
    {
        $this->clients = $this->clientService->searchClients($this->clientSearch);
        return view('livewire.admin.finance.templates');
    }

    public function loadTemplates()
    {
        $this->templates = Template::with(['category', 'client', 'cashRegister', 'project'])
            ->where('user_id', Auth::id())
            ->get();
    }

    public function openForm()
    {
        $this->resetForm();
        $this->showForm = true;
        $this->isDirty = false;
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

    public function closeTForm()
    {
        $this->showTForm = false;
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

    private function resetForm()
    {
        $this->templateName = '';
        $this->templateIcon = '';
        $this->templateId = null;
        $this->templateAmount = '';
        $this->type = '1';
        $this->categoryId = '';
        $this->templateTransactionDate = now()->toDateString();
        $this->templateNote = '';
        $this->client_id = null;
        $this->client_search = '';
        $this->templateClients = [];
    }

    public function updated($propertyName)
    {
        $this->isDirty = true;
    }

    public function saveTemplate()
    {
        $this->validate([
            'templateName' => 'required|string|max:255',
            'templateIcon' => 'required|string|max:255',
            'templateAmount' => 'required|numeric',
            'type' => 'required|in:1,0',
            'categoryId' => 'nullable|exists:transaction_categories,id',
            'templateTransactionDate' => 'nullable|date',
            'templateNote' => 'nullable|string',
            'client_id' => 'nullable|exists:clients,id',
            'projectId' => 'nullable|exists:projects,id',
        ]);

        Template::updateOrCreate(
            ['id' => $this->templateId],
            [
                'name' => $this->templateName,
                'icon' => $this->templateIcon,
                'amount' => $this->templateAmount,
                'type' => $this->type,
                'category_id' => $this->categoryId,
                'transaction_date' => $this->templateTransactionDate,
                'note' => $this->templateNote,
                'client_id' => $this->client_id,
                'user_id' => Auth::id(),
                'cash_register_id' => $this->cashId,
                'project_id' => $this->projectId,
            ]
        );

        session()->flash('message', $this->templateId ? 'Шаблон успешно обновлен.' : 'Шаблон успешно создан.');
        $this->isDirty = false; // Reset dirty status after saving
        $this->closeForm();
        $this->loadTemplates();
    }

    public function edit($id)
    {
        $template = Template::with(['category', 'client', 'cashRegister', 'project'])
            ->where('id', $id)
            ->where('user_id', Auth::id())
            ->first();
        if ($template) {
            $this->templateId = $template->id;
            $this->templateName = $template->name;
            $this->templateIcon = $template->icon;
            $this->templateAmount = $template->amount;
            $this->categoryId = $template->category_id;
            $this->templateTransactionDate = $template->transaction_date;
            $this->templateNote = $template->note;
            $this->client_id = $template->client_id;
            $this->cashId = $template->cash_register_id;
            $this->projectId = $template->project_id;
            $this->showForm = true;
            $this->isDirty = false;
        }
    }

    public function delete($id)
    {
        $template = Template::where('id', $id)
            ->where('user_id', Auth::id())
            ->first();
        if ($template) {
            $template->delete();
            session()->flash('message', 'Шаблон успешно удален.');
            $this->loadTemplates();
        }
    }

    public function updatedType($value)
    {
        $this->filteredCategories = TransactionCategory::where('type', $value)->get();
        $this->isDirty = true;
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

    public function applyTemplate($templateId)
    {
        $template = Template::with(['category', 'client', 'cashRegister', 'project'])
            ->where('id', $templateId)
            ->where('user_id', Auth::id())
            ->first();
        if (!$template) return;

        $this->applyTemplateId = $template->id;
        $this->templateName = $template->name;
        $this->templateIcon = $template->icon;
        $this->templateAmount = $template->amount;
        $this->type = $template->type;
        $this->categoryId = $template->category_id;
        $this->templateTransactionDate = $template->transaction_date;
        $this->templateNote = $template->note;
        $this->client_id = $template->client_id;
        $this->cashId = $template->cash_register_id;
        $this->showTForm = true;
        $this->isDirty = true;
    }

    public function saveAppliedTemplate()
    {
        $this->validate([
            'templateAmount' => 'required|numeric',
            'templateTransactionDate' => 'required|date',
            'templateNote' => 'nullable|string',
            'client_id' => 'nullable|exists:clients,id',
            'cashId' => 'required|exists:cash_registers,id',
        ]);

        $cashRegister = CashRegister::find($this->cashId);
        if (!$cashRegister) return;

        $currency = $cashRegister->currency;
        // Append initial amount note
        $initialNote = sprintf(
            "(Изначальная сумма: %s %s)",
            number_format($this->templateAmount, 2),
            $currency->code ?? ''
        );
        $this->templateNote = trim($this->templateNote)
            ? $this->templateNote . "\n" . $initialNote
            : $initialNote;
            
        $exchangeRate = $currency->currentExchangeRate()->exchange_rate;

        $transaction = new Transaction();
        $transaction->cash_register_id = $cashRegister->id;
        $transaction->type = $this->type;
        $transaction->category_id = $this->categoryId;
        $transaction->client_id = $this->client_id;
        $transaction->user_id = Auth::id();
        $transaction->amount = $this->templateAmount;
        $transaction->transaction_date = $this->templateTransactionDate;
        $transaction->note = $this->templateNote;
        $transaction->currency_id = $currency->id;
        $transaction->save();

        if ($this->type == 1) {
            $cashRegister->balance += $this->templateAmount;
        } else {
            $cashRegister->balance -= $this->templateAmount;
        }
        $cashRegister->save();

        session()->flash('message', 'Транзакция успешно создана из шаблона.');
        $this->isDirty = false; 
        $this->showTForm = false;
    }

}
