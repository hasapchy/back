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
use App\Models\FinancialTransaction;
use Livewire\Attributes\Lazy;
use Illuminate\Support\Facades\Cache;
use App\Services\ClientService;


#[Lazy]
class Templates extends Component
{
    public $templateName;
    public $templateIcon;
    public $templates;
    public $templateId;
    public $showTemplateForm = false;
    public $templateAmount;
    public $type = '1';
    public $templateCategoryId;
    public $templateTransactionDate;
    public $templateNote;
    public $client_id;
    public $filteredCategories = [];
    public $client_search = '';
    public $templateClients = [];
    public $currencies = [];
    public $cashRegisters = [];
    public $selectedCashRegisterId = null;
    public $showApplyTemplateForm = false;
    public $applyTemplateId;
    public $showConfirmationModal = false;
    public $selectedProjectId = null;
    public $projects = [];
    public $isDirty = false;
    public $clientSearch = ''; // Added for client search
    public $clientResults = []; // Added for client search results
    public $selectedClient; // Added for selected client
    public $clients = [];
    protected $listeners = ['openTemplateForm', 'confirmClose'];
    protected $clientService;

    public function boot(ClientService $clientService)
    {
        $this->clientService = $clientService;
    }

    public function mount()
    {
        $this->loadTemplates();
        $this->filteredCategories = Cache::remember('transaction_categories_type_1', 60, function () {
            return TransactionCategory::where('type', '1')->get();
        });
        $this->currencies = Cache::remember('currencies_all', 60, function () {
            return Currency::all();
        });
        $this->cashRegisters = Cache::remember('cash_registers_all', 60, function () {
            return CashRegister::all();
        });
        $this->projects = Cache::remember(Auth::user()->is_admin ? 'projects_all' : 'projects_user_' . Auth::id(), 60, function () {
            return Auth::user()->is_admin
                ? Project::with('users')->get()
                : Project::whereJsonContains('users', Auth::id())->with('users')->get();
        });
    }

    public function loadTemplates()
    {
        $this->templates = Template::with(['category', 'client', 'cashRegister', 'project'])
            ->where('user_id', Auth::id())
            ->get();
    }




    public function openTemplateForm()
    {
        $this->resetTemplateForm();
        $this->showTemplateForm = true;
        $this->isDirty = false; // Reset dirty status when opening form
    }

    public function closeTemplateForm()
    {
        if ($this->isDirty) {
            $this->showConfirmationModal = true;
        } else {
            $this->resetTemplateForm();
            $this->showTemplateForm = false;
        }
    }

    public function confirmClose($confirm = false)
    {
        if ($confirm) {
            $this->resetTemplateForm();
            $this->isDirty = false; // Reset dirty status
            $this->showTemplateForm = false; // Ensure the form is hidden
        }
        $this->showConfirmationModal = false;
    }

    private function resetTemplateForm()
    {
        $this->templateName = '';
        $this->templateIcon = '';
        $this->templateId = null;
        $this->templateAmount = '';
        $this->type = '1';
        $this->templateCategoryId = '';
        $this->templateTransactionDate = now()->toDateString();
        $this->templateNote = '';
        $this->client_id = null;
        $this->client_search = '';
        $this->templateClients = [];
    }

    public function updated($propertyName)
    {
        // Whenever any bound property changes, mark the form as dirty
        $this->isDirty = true;
    }

    public function saveTemplate()
    {
        $this->validate([
            'templateName' => 'required|string|max:255',
            'templateIcon' => 'required|string|max:255',
            'templateAmount' => 'required|numeric',
            'type' => 'required|in:1,0',
            'templateCategoryId' => 'nullable|exists:transaction_categories,id',
            'templateTransactionDate' => 'nullable|date',
            'templateNote' => 'nullable|string',
            'client_id' => 'nullable|exists:clients,id',
            'selectedProjectId' => 'nullable|exists:projects,id',
        ]);

        Template::updateOrCreate(
            ['id' => $this->templateId],
            [
                'name' => $this->templateName,
                'icon' => $this->templateIcon,
                'amount' => $this->templateAmount,
                'type' => $this->type,
                'category_id' => $this->templateCategoryId,
                'transaction_date' => $this->templateTransactionDate,
                'note' => $this->templateNote,
                'client_id' => $this->client_id,
                'user_id' => Auth::id(),
                'cash_register_id' => $this->selectedCashRegisterId,
                'project_id' => $this->selectedProjectId,
            ]
        );

        session()->flash('message', $this->templateId ? 'Шаблон успешно обновлен.' : 'Шаблон успешно создан.');
        $this->isDirty = false; // Reset dirty status after saving
        $this->closeTemplateForm();
        $this->loadTemplates();
    }

    public function editTemplate($id)
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
            $this->templateCategoryId = $template->category_id;
            $this->templateTransactionDate = $template->transaction_date;
            $this->templateNote = $template->note;
            $this->client_id = $template->client_id;
            $this->selectedCashRegisterId = $template->cash_register_id;
            $this->selectedProjectId = $template->project_id;
            $this->showTemplateForm = true;
            $this->isDirty = false; // Reset dirty status when editing
        }
    }

    public function deleteTemplate($id)
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

        // Pre-fill form values from the template
        $this->applyTemplateId = $template->id;
        $this->templateName = $template->name;
        $this->templateIcon = $template->icon;
        $this->templateAmount = $template->amount;
        $this->type = $template->type;
        $this->templateCategoryId = $template->category_id;
        $this->templateTransactionDate = $template->transaction_date;
        $this->templateNote = $template->note;
        $this->client_id = $template->client_id;
        $this->selectedCashRegisterId = $template->cash_register_id;

        $this->showApplyTemplateForm = true;
        $this->isDirty = true;
    }

    public function saveAppliedTemplate()
    {
        $this->validate([
            'templateAmount' => 'required|numeric',
            'templateTransactionDate' => 'required|date',
            'templateNote' => 'nullable|string',
            'client_id' => 'nullable|exists:clients,id',
            'selectedCashRegisterId' => 'required|exists:cash_registers,id',
        ]);

        $cashRegister = CashRegister::find($this->selectedCashRegisterId);
        if (!$cashRegister) return;

        $currency = $cashRegister->currency;
        $exchangeRate = $currency->currentExchangeRate()->exchange_rate;

        $transaction = new FinancialTransaction();
        $transaction->cash_register_id = $cashRegister->id;
        $transaction->type = $this->type;
        $transaction->category_id = $this->templateCategoryId;
        $transaction->client_id = $this->client_id;
        $transaction->user_id = Auth::id();
        $transaction->amount = $this->templateAmount;
        $transaction->transaction_date = $this->templateTransactionDate;
        $transaction->note = $this->templateNote;
        $transaction->currency_id = $currency->id;
        // $transaction->exchange_rate = $exchangeRate;
        $transaction->save();

        // Update the cash register balance
        if ($this->type == 1) {
            $cashRegister->balance += $this->templateAmount;
        } else {
            $cashRegister->balance -= $this->templateAmount;
        }
        $cashRegister->save();

        session()->flash('message', 'Транзакция успешно создана из шаблона.');
        $this->isDirty = false; // Reset dirty status after saving
        $this->showApplyTemplateForm = false;
        // $this->dispatch('refreshPage');
    }

    public function closeApplyTemplateForm()
    {
        $this->showApplyTemplateForm = false;
    }

    public function render()
    {

        $this->clients = $this->clientService->searchClients($this->clientSearch);
        return view('livewire.admin.finance.templates', [
            // 'templates' => $this->templates,
            // 'filteredCategories' => $this->filteredCategories,
            // 'templateClients' => $this->templateClients,
            // 'currencies' => $this->currencies,
            // 'cashRegisters' => $this->cashRegisters,
           
        ]);
    }
}
