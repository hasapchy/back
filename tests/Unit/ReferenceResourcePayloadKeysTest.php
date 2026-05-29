<?php

namespace Tests\Unit;

use App\Http\Resources\CashRegisterReferenceResource;
use App\Http\Resources\HolidayReferenceResource;
use App\Http\Resources\DepartmentReferenceResource;
use App\Http\Resources\LeaveReferenceResource;
use App\Http\Resources\MessageTemplateReferenceResource;
use App\Http\Resources\TransactionTemplateReferenceResource;
use App\Http\Resources\CategoryReferenceResource;
use App\Http\Resources\ClientSearchResource;
use App\Http\Resources\LeaveTypeReferenceResource;
use App\Http\Resources\OrderStatusCategoryReferenceResource;
use App\Http\Resources\OrderStatusReferenceResource;
use App\Http\Resources\ProductSearchResource;
use App\Http\Resources\ProjectReferenceResource;
use App\Http\Resources\ProjectStatusReferenceResource;
use App\Http\Resources\RoleReferenceResource;
use App\Http\Resources\TaskReferenceResource;
use App\Http\Resources\TaskStatusReferenceResource;
use App\Http\Resources\TransactionCategoryReferenceResource;
use App\Http\Resources\UserSearchResource;
use App\Http\Resources\WarehouseReferenceResource;
use App\Models\CashRegister;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientsPhone;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\MessageTemplate;
use App\Models\OrderStatus;
use App\Models\OrderStatusCategory;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Template;
use App\Models\TransactionCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReferenceResourcePayloadKeysTest extends TestCase
{

    /**
     * @return Request
     */
    private function apiRequest(): Request
    {
        return Request::create('/', 'GET');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertTopLevelKeysMatchConfig(string $configKey, array $payload): void
    {
        $expected = config('reference_contracts.reference_top_level_keys.'.$configKey);
        $this->assertIsArray($expected, 'reference_top_level_keys.'.$configKey);
        $this->assertEqualsCanonicalizing($expected, array_keys($payload), $configKey);
    }

    /**
     * @param  array<string, mixed>|null  $nested
     */
    private function assertNestedKeysMatchConfig(string $configPath, ?array $nested): void
    {
        if ($nested === null) {
            return;
        }
        $map = config('reference_contracts.reference_nested_keys', []);
        $this->assertIsArray($map, 'reference_nested_keys');
        $expected = $map[$configPath] ?? null;
        $this->assertIsArray($expected, 'reference_nested_keys.'.$configPath);
        $this->assertEqualsCanonicalizing($expected, array_keys($nested), $configPath);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertSearchItemKeysMatchConfig(string $configKey, array $payload): void
    {
        $expected = config('reference_contracts.search_item_top_level_keys.'.$configKey);
        $this->assertIsArray($expected, 'search_item_top_level_keys.'.$configKey);
        $this->assertEqualsCanonicalizing($expected, array_keys($payload), $configKey);
    }

    /**
     * @return void
     */
    public function test_warehouse_reference_keys(): void
    {
        $user = User::factory()->create();
        $warehouse = Warehouse::factory()->create();
        $warehouse->users()->sync([$user->id]);
        $warehouse->refresh()->load('users');

        $payload = (new WarehouseReferenceResource($warehouse))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('warehouses', $payload);
        $this->assertNotEmpty($payload['users'] ?? []);
        $this->assertNestedKeysMatchConfig('warehouses.users_item', $payload['users'][0]);
    }

    /**
     * @return void
     */
    public function test_cash_register_reference_keys(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $currency = Currency::factory()->create(['company_id' => $company->id]);
        $cashRegister = CashRegister::factory()->create([
            'company_id' => $company->id,
            'currency_id' => $currency->id,
            'is_working_minus' => false,
        ]);
        $cashRegister->users()->sync([$user->id]);
        $cashRegister->refresh()->load(['currency', 'users']);

        $payload = (new CashRegisterReferenceResource($cashRegister))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('cash_registers', $payload);
        $this->assertNotNull($payload['currency'] ?? null);
        $this->assertNestedKeysMatchConfig('cash_registers.currency', $payload['currency']);
        $this->assertNotEmpty($payload['users'] ?? []);
        $this->assertNestedKeysMatchConfig('cash_registers.users_item', $payload['users'][0]);
    }

    /**
     * @return void
     */
    public function test_category_reference_keys(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $category = Category::factory()->create([
            'company_id' => $company->id,
            'creator_id' => $user->id,
        ]);
        $category->users()->sync([$user->id]);
        $category->refresh()->load('users');
        $category->setRelation('creator', User::query()->findOrFail($category->creator_id));

        $payload = (new CategoryReferenceResource($category))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('categories', $payload);
        $this->assertNestedKeysMatchConfig('categories.creator', $payload['creator']);
        $this->assertNotEmpty($payload['users'] ?? []);
        $this->assertNestedKeysMatchConfig('categories.users_item', $payload['users'][0]);
    }

    /**
     * @return void
     */
    public function test_order_status_reference_keys(): void
    {
        $user = User::factory()->create();
        $category = OrderStatusCategory::factory()->create(['creator_id' => $user->id]);
        $status = OrderStatus::factory()->create(['category_id' => $category->id]);
        $status->refresh()->load('category');

        $payload = (new OrderStatusReferenceResource($status))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('order_statuses', $payload);
        $this->assertNotNull($payload['category'] ?? null);
        $this->assertNestedKeysMatchConfig('order_statuses.category', $payload['category']);
    }

    /**
     * @return void
     */
    public function test_order_status_category_reference_keys(): void
    {
        $user = User::factory()->create();
        $item = OrderStatusCategory::factory()->create(['creator_id' => $user->id]);

        $payload = (new OrderStatusCategoryReferenceResource($item))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('order_status_categories', $payload);
    }

    /**
     * @return void
     */
    public function test_project_status_reference_keys(): void
    {
        $user = User::factory()->create();
        $status = ProjectStatus::factory()->create(['creator_id' => $user->id]);
        $status->refresh()->load('creator');

        $payload = (new ProjectStatusReferenceResource($status))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('project_statuses', $payload);
        $this->assertNotNull($payload['user'] ?? null);
        $this->assertNestedKeysMatchConfig('project_statuses.user', $payload['user']);
    }

    /**
     * @return void
     */
    public function test_task_status_reference_keys(): void
    {
        $user = User::factory()->create();
        $status = TaskStatus::create([
            'name' => 'Task status '.uniqid(),
            'color' => '#111111',
            'kanban_outcome' => null,
            'creator_id' => $user->id,
        ]);
        $status->refresh()->load('creator');

        $payload = (new TaskStatusReferenceResource($status))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('task_statuses', $payload);
        $this->assertNotNull($payload['user'] ?? null);
        $this->assertNestedKeysMatchConfig('task_statuses.user', $payload['user']);
    }

    /**
     * @return void
     */
    public function test_transaction_category_reference_keys(): void
    {
        $user = User::factory()->create();
        $parent = TransactionCategory::factory()->create([
            'creator_id' => $user->id,
            'parent_id' => null,
        ]);
        $child = TransactionCategory::factory()->create([
            'creator_id' => $user->id,
            'parent_id' => $parent->id,
        ]);
        $child->refresh()->load(['parent', 'creator']);

        $payload = (new TransactionCategoryReferenceResource($child))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('transaction_categories', $payload);
        $this->assertNotNull($payload['parent'] ?? null);
        $this->assertNestedKeysMatchConfig('transaction_categories.parent', $payload['parent']);
        $this->assertNotNull($payload['creator'] ?? null);
        $this->assertNestedKeysMatchConfig('transaction_categories.creator', $payload['creator']);
    }

    /**
     * @return void
     */
    public function test_role_reference_keys(): void
    {
        $company = Company::factory()->create();
        $role = Role::create([
            'name' => 'contract_role_'.uniqid(),
            'guard_name' => 'api',
            'company_id' => $company->id,
        ]);

        $payload = (new RoleReferenceResource($role))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('roles', $payload);
    }

    /**
     * @return void
     */
    public function test_leave_type_reference_keys(): void
    {
        $item = LeaveType::factory()->create();

        $payload = (new LeaveTypeReferenceResource($item))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('leave_types', $payload);
    }

    /**
     * @return void
     */
    public function test_product_search_resource_keys(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $unit = Unit::query()->first();
        if ($unit === null) {
            $unit = Unit::create(['name' => 'Unit', 'short_name' => 'u']);
        }
        $category = Category::factory()->create([
            'company_id' => $company->id,
            'creator_id' => $user->id,
        ]);
        $product = Product::factory()->create([
            'unit_id' => $unit->id,
            'creator_id' => $user->id,
        ]);
        $product->categories()->sync([$category->id]);
        ProductPrice::query()->updateOrCreate(
            ['product_id' => $product->id],
            ['retail_price' => 10, 'wholesale_price' => 8, 'purchase_price' => 5]
        );
        $product->load(['categories', 'unit', 'prices']);
        $product->category_name = $category->name;
        $product->unit_name = $unit->name;
        $product->unit_short_name = $unit->short_name;
        $product->stock_quantity = 2.0;
        $price = $product->prices->first();
        $product->retail_price = $price?->retail_price;
        $product->wholesale_price = $price?->wholesale_price;
        $product->purchase_price = $price?->purchase_price;
        $product->stock_by_units = [];
        $product->alternate_unit_options = [];

        $payload = (new ProductSearchResource($product))->toArray($this->apiRequest());
        $this->assertSearchItemKeysMatchConfig('products', $payload);
    }

    /**
     * @return void
     */
    public function test_user_search_resource_keys(): void
    {
        $user = User::factory()->create();

        $payload = (new UserSearchResource($user))->toArray($this->apiRequest());
        $this->assertSearchItemKeysMatchConfig('users', $payload);
    }

    /**
     * @return void
     */
    public function test_client_search_resource_keys(): void
    {
        $company = Company::factory()->create();
        $client = Client::factory()->create(['company_id' => $company->id]);
        ClientsPhone::create([
            'client_id' => $client->id,
            'phone' => '+10000000000',
        ]);
        $client->load('phones');

        $payload = (new ClientSearchResource($client))->toArray($this->apiRequest());
        $this->assertSearchItemKeysMatchConfig('clients', $payload);
    }

    /**
     * @return void
     */
    public function test_department_reference_keys(): void
    {
        $company = Company::factory()->create();
        $head = User::factory()->create();
        $deputy = User::factory()->create();
        $department = Department::make([
            'id' => 1,
            'title' => 'Main',
            'description' => 'D',
            'parent_id' => null,
            'head_id' => $head->id,
            'deputy_head_id' => $deputy->id,
            'company_id' => $company->id,
        ]);
        $department->exists = true;
        $department->syncOriginal();
        $department->setRelation('users', collect([$head]));
        $department->setRelation('head', $head);
        $department->setRelation('deputyHead', $deputy);

        $payload = (new DepartmentReferenceResource($department))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('departments', $payload);
        $this->assertNestedKeysMatchConfig('departments.users_item', $payload['users'][0]);
        $this->assertNestedKeysMatchConfig('departments.head', $payload['head']);
        $this->assertNestedKeysMatchConfig('departments.deputy_head', $payload['deputy_head']);
    }

    /**
     * @return void
     */
    public function test_message_template_reference_keys(): void
    {
        $company = Company::factory()->create();
        $creator = User::factory()->create();
        $template = MessageTemplate::make([
            'id' => 1,
            'type' => 'birthday',
            'name' => 'Greeting',
            'company_id' => $company->id,
            'creator_id' => $creator->id,
            'is_active' => true,
        ]);
        $template->exists = true;
        $template->syncOriginal();
        $template->setRelation('creator', $creator);
        $template->setRelation('company', $company);

        $payload = (new MessageTemplateReferenceResource($template))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('message_templates', $payload);
        $this->assertNestedKeysMatchConfig('message_templates.creator', $payload['creator']);
        $this->assertNestedKeysMatchConfig('message_templates.company', $payload['company']);
    }

    /**
     * @return void
     */
    public function test_company_holiday_reference_keys(): void
    {
        $company = Company::factory()->create();
        $holiday = Holiday::make([
            'id' => 1,
            'company_id' => $company->id,
            'name' => 'Day off',
            'date' => now()->toDateString(),
            'end_date' => null,
            'is_recurring' => true,
            'color' => '#FF5733',
            'icon' => 'fa-solid fa-calendar-day',
        ]);
        $holiday->exists = true;
        $holiday->syncOriginal();

        $payload = (new HolidayReferenceResource($holiday))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('holidays', $payload);
    }

    /**
     * @return void
     */
    public function test_transaction_template_reference_keys(): void
    {
        $company = Company::factory()->create();
        $currency = Currency::factory()->create(['company_id' => $company->id]);
        $creator = User::factory()->create();
        $cashRegister = CashRegister::factory()->create([
            'company_id' => $company->id,
            'currency_id' => $currency->id,
        ]);
        $category = TransactionCategory::factory()->create(['creator_id' => $creator->id]);
        $project = Project::factory()->create(['company_id' => $company->id]);
        $client = Client::factory()->create(['company_id' => $company->id]);
        $template = Template::make([
            'id' => 1,
            'cash_id' => $cashRegister->id,
            'name' => 'T',
            'icon' => 'fas fa-star',
            'amount' => 10,
            'currency_id' => $currency->id,
            'type' => false,
            'category_id' => $category->id,
            'note' => 'N',
            'client_id' => $client->id,
            'creator_id' => $creator->id,
            'project_id' => $project->id,
        ]);
        $template->exists = true;
        $template->syncOriginal();
        $template->setRelation('cashRegister', $cashRegister);
        $template->setRelation('currency', $currency);
        $template->setRelation('category', $category);
        $template->setRelation('project', $project);
        $template->setRelation('client', $client);
        $template->setRelation('creator', $creator);

        $payload = (new TransactionTemplateReferenceResource($template))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('transaction_templates', $payload);
        $this->assertNestedKeysMatchConfig('transaction_templates.cash_register', $payload['cash_register']);
        $this->assertNestedKeysMatchConfig('transaction_templates.category', $payload['category']);
        $this->assertNestedKeysMatchConfig('transaction_templates.client', $payload['client']);
        $this->assertNestedKeysMatchConfig('transaction_templates.creator', $payload['creator']);
        $this->assertNestedKeysMatchConfig('transaction_templates.currency', $payload['currency']);
        $this->assertNestedKeysMatchConfig('transaction_templates.project', $payload['project']);
    }

    /**
     * @return void
     */
    public function test_leave_reference_keys(): void
    {
        $company = Company::factory()->create();
        $leaveType = LeaveType::factory()->create();
        $user = User::factory()->create();
        $leave = Leave::make([
            'id' => 1,
            'leave_type_id' => $leaveType->id,
            'user_id' => $user->id,
            'company_id' => $company->id,
            'comment' => 'C',
            'date_from' => now(),
            'date_to' => now()->addDay(),
        ]);
        $leave->exists = true;
        $leave->syncOriginal();
        $leave->setRelation('leaveType', $leaveType);
        $leave->setRelation('user', $user);

        $payload = (new LeaveReferenceResource($leave))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('leaves', $payload);
        $this->assertNestedKeysMatchConfig('leaves.leave_type', $payload['leave_type']);
        $this->assertNestedKeysMatchConfig('leaves.user', $payload['user']);
    }

    /**
     * @return void
     */
    public function test_project_reference_keys(): void
    {
        $company = Company::factory()->create();
        $client = Client::factory()->create(['company_id' => $company->id]);
        $currency = Currency::factory()->create(['company_id' => $company->id]);
        $creator = User::factory()->create();
        $status = ProjectStatus::factory()->create(['creator_id' => $creator->id]);
        $member = User::factory()->create();
        $project = Project::factory()->create([
            'company_id' => $company->id,
            'client_id' => $client->id,
            'currency_id' => $currency->id,
            'creator_id' => $creator->id,
            'status_id' => $status->id,
        ]);
        $project->users()->syncWithoutDetaching([$member->id]);
        $project->load(['client', 'currency', 'status', 'creator', 'users']);

        $payload = (new ProjectReferenceResource($project))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('projects', $payload);
        $this->assertNestedKeysMatchConfig('projects.client', $payload['client']);
        $this->assertNestedKeysMatchConfig('projects.creator', $payload['creator']);
        $this->assertNestedKeysMatchConfig('projects.currency', $payload['currency']);
        $this->assertNestedKeysMatchConfig('projects.status', $payload['status']);
        $this->assertNotEmpty($payload['users']);
        $this->assertNestedKeysMatchConfig('projects.users_item', $payload['users'][0]);
    }

    /**
     * @return void
     */
    public function test_task_reference_keys(): void
    {
        $company = Company::factory()->create();
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $status = TaskStatus::create([
            'name' => 'Task status '.uniqid(),
            'color' => '#222222',
            'kanban_outcome' => null,
            'creator_id' => $userA->id,
        ]);
        $project = Project::factory()->create(['company_id' => $company->id]);
        $task = Task::query()->create([
            'title' => 'T',
            'description' => 'Full description',
            'creator_id' => $userA->id,
            'supervisor_id' => $userA->id,
            'executor_id' => $userB->id,
            'project_id' => $project->id,
            'company_id' => $company->id,
            'status_id' => $status->id,
            'deadline' => null,
            'files' => [],
            'comments' => [],
            'checklist' => [],
        ]);
        $task->load(['creator', 'supervisor', 'executor', 'project', 'status']);

        $payload = (new TaskReferenceResource($task))->toArray($this->apiRequest());
        $this->assertTopLevelKeysMatchConfig('tasks', $payload);
        $this->assertNestedKeysMatchConfig('tasks.status', $payload['status']);
        $this->assertNestedKeysMatchConfig('tasks.creator', $payload['creator']);
        $this->assertNestedKeysMatchConfig('tasks.supervisor', $payload['supervisor']);
        $this->assertNestedKeysMatchConfig('tasks.executor', $payload['executor']);
        $this->assertNestedKeysMatchConfig('tasks.project', $payload['project']);
    }
}
