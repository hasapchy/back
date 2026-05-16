<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Support\ReferencePayloadBudget;
use App\Support\ReferenceTelemetry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReferencePayloadBudgetTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();


        config(['reference_contracts.canary.enabled' => false]);
    }

    /**
     * @return array{0: TestResponse, 1: string}
     */
    private function getAuthenticated(string $uri): array
    {
        $company = Company::factory()->create();
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $admin->companies()->attach($company->id);

        $response = $this->withApiTokenForCompany($admin, (int) $company->id)
            ->getJson($uri);

        return [$response, $uri];
    }

    /**
     * @return void
     */
    private function assertResponseDataWithinBudget(TestResponse $response, string $budgetKey, string $uri): void
    {
        $limit = ReferencePayloadBudget::limitFor($budgetKey);
        $this->assertNotNull($limit, 'Не задан payload_budget_bytes.'.$budgetKey);

        $response->assertStatus(200);
        $json = $response->json();
        $this->assertIsArray($json, $uri);
        $bytes = ReferencePayloadBudget::jsonEncodedByteLength($json['data'] ?? null);
        $this->assertLessThanOrEqual(
            $limit,
            $bytes,
            sprintf('%s: data JSON %d bytes, лимит %d', $uri, $bytes, $limit)
        );

        ReferenceTelemetry::maybeLogReferenceRequest($uri, $bytes);
    }

    /**
     * @return void
     */
    public function test_wave1_reference_all_endpoints_respect_payload_budgets(): void
    {
        $cases = [
            ['/api/warehouses/all', 'warehouses_all'],
            ['/api/cash_registers/all', 'cash_registers_all'],
            ['/api/categories/all', 'categories_all'],
            ['/api/order_statuses/all', 'order_statuses_all'],
            ['/api/project-statuses/all', 'project_statuses_all'],
            ['/api/task-statuses/all', 'task_statuses_all'],
            ['/api/roles/all', 'roles_all'],
            ['/api/leave_types/all', 'leave_types_all'],
            ['/api/order_status_categories/all', 'order_status_categories_all'],
            ['/api/transaction_categories/all', 'transaction_categories_all'],
        ];

        foreach ($cases as [$uri, $budgetKey]) {
            [$response] = $this->getAuthenticated($uri);
            $this->assertResponseDataWithinBudget($response, $budgetKey, $uri);
        }
    }

    /**
     * @return void
     */
    public function test_wave1_reference_index_endpoints_respect_payload_budgets(): void
    {
        $cases = [
            ['/api/warehouses?page=1&per_page=20', 'warehouses_index'],
            ['/api/cash_registers?page=1&per_page=20', 'cash_registers_index'],
            ['/api/categories?page=1&per_page=20', 'categories_index'],
            ['/api/categories/parents', 'categories_parents'],
            ['/api/order_statuses?page=1&per_page=20', 'order_statuses_index'],
            ['/api/project-statuses?page=1&per_page=20', 'project_statuses_index'],
            ['/api/task-statuses?page=1&per_page=20', 'task_statuses_index'],
            ['/api/transaction_categories?page=1&per_page=20', 'transaction_categories_index'],
            ['/api/roles?page=1&per_page=20', 'roles_index'],
            ['/api/leave_types?page=1&per_page=20', 'leave_types_index'],
            ['/api/order_status_categories?page=1&per_page=20', 'order_status_categories_index'],
        ];

        foreach ($cases as [$uri, $budgetKey]) {
            [$response] = $this->getAuthenticated($uri);
            $this->assertResponseDataWithinBudget($response, $budgetKey, $uri);
        }
    }

    /**
     * @return void
     */
    public function test_wave1_search_endpoints_respect_payload_budgets(): void
    {
        [$response] = $this->getAuthenticated('/api/users/search?search_request=ab');
        $this->assertResponseDataWithinBudget($response, 'users_search', '/api/users/search');

        [$response] = $this->getAuthenticated('/api/products/search?search=ab&per_page=20&page=1');
        $this->assertResponseDataWithinBudget($response, 'products_search', '/api/products/search');

        [$response] = $this->getAuthenticated('/api/clients/search?search_request=ab');
        $this->assertResponseDataWithinBudget($response, 'clients_search', '/api/clients/search');
    }

    /**
     * @return void
     */
    public function test_wave2_reference_list_endpoints_respect_payload_budgets(): void
    {
        $cases = [
            ['/api/departments?page=1&per_page=20', 'departments_index'],
            ['/api/departments/all', 'departments_all'],
            ['/api/message-templates?page=1&per_page=20', 'message_templates_index'],
            ['/api/message-templates/all', 'message_templates_all'],
            ['/api/company-holidays?page=1&per_page=20', 'company_holidays_index'],
            ['/api/company-holidays/all', 'company_holidays_all'],
        ];

        foreach ($cases as [$uri, $budgetKey]) {
            [$response] = $this->getAuthenticated($uri);
            $this->assertResponseDataWithinBudget($response, $budgetKey, $uri);
        }
    }

    /**
     * @return void
     */
    public function test_wave3_reference_list_endpoints_respect_payload_budgets(): void
    {
        Permission::firstOrCreate(['name' => 'transaction_templates_view_all', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'transaction_templates_view_own', 'guard_name' => 'api']);
        Permission::firstOrCreate(['name' => 'leaves_view_all', 'guard_name' => 'api']);

        $company = Company::factory()->create();
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $admin->companies()->attach($company->id);
        $admin->givePermissionTo([
            'transaction_templates_view_all',
            'transaction_templates_view_own',
            'leaves_view_all',
        ]);

        $cases = [
            ['/api/transaction-templates?page=1&per_page=20', 'transaction_templates_index'],
            ['/api/transaction-templates/all', 'transaction_templates_all'],
            ['/api/leaves?page=1&per_page=20', 'leaves_index'],
            ['/api/leaves/all', 'leaves_all'],
        ];

        foreach ($cases as [$uri, $budgetKey]) {
            $response = $this->withApiTokenForCompany($admin, (int) $company->id)->getJson($uri);
            $this->assertResponseDataWithinBudget($response, $budgetKey, $uri);
        }
    }

    /**
     * @return void
     */
    public function test_wave4_projects_all_respects_payload_budget(): void
    {
        [$response] = $this->getAuthenticated('/api/projects/all?active_only=1');
        $this->assertResponseDataWithinBudget($response, 'projects_all', '/api/projects/all');
    }

    /**
     * @return void
     */
    public function test_projects_index_respects_payload_budget(): void
    {
        [$response] = $this->getAuthenticated('/api/projects?page=1&per_page=20');
        $this->assertResponseDataWithinBudget($response, 'projects_index', '/api/projects');
    }

    /**
     * @return void
     */
    public function test_wave5_tasks_index_respects_payload_budget(): void
    {
        Permission::firstOrCreate(['name' => 'tasks_view_all', 'guard_name' => 'api']);

        $company = Company::factory()->create();
        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $admin->companies()->attach($company->id);
        $admin->givePermissionTo(['tasks_view_all']);

        $response = $this->withApiTokenForCompany($admin, (int) $company->id)
            ->getJson('/api/tasks?page=1&per_page=20');
        $this->assertResponseDataWithinBudget($response, 'tasks_index', '/api/tasks');
    }
}
