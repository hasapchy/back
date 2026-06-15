<?php

namespace App\Support;

use App\Http\Resources\CashRegisterReferenceResource;
use App\Http\Resources\CashRegisterResource;
use App\Http\Resources\HolidayReferenceResource;
use App\Http\Resources\HolidayResource;
use App\Http\Resources\DepartmentReferenceResource;
use App\Http\Resources\DepartmentResource;
use App\Http\Resources\LeaveReferenceResource;
use App\Http\Resources\LeaveResource;
use App\Http\Resources\MessageTemplateReferenceResource;
use App\Http\Resources\MessageTemplateResource;
use App\Enums\TaskComplexity;
use App\Enums\TaskPriority;
use App\Http\Resources\ProjectReferenceResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\TaskReferenceResource;
use App\Http\Resources\TaskResource;
use App\Http\Resources\TransactionTemplateReferenceResource;
use App\Http\Resources\TransactionTemplateResource;
use App\Http\Resources\WarehouseReferenceResource;
use App\Http\Resources\WarehouseResource;
use App\Models\CashRegister;
use App\Models\Client;
use App\Models\Company;
use App\Models\Holiday;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Leave;
use App\Models\LeaveType;
use App\Models\MessageTemplate;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Template;
use App\Models\TransactionCategory;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

final class ReferencePayloadBenchmark
{
    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runWarehouses(array $counts): array
    {
        $userA = User::factory()->make([
            'id' => 100_001,
            'email' => 'bench-a-100001@local.test',
        ]);
        $userB = User::factory()->make([
            'id' => 100_002,
            'email' => 'bench-b-100002@local.test',
        ]);

        $rows = [];
        foreach ($counts as $n) {
            $items = self::warehouseItems($n, $userA, $userB);
            $full = self::measureResolvedPayload(fn () => WarehouseResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => WarehouseReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('warehouses', $n, $full, $reference);
            self::emitTelemetry('benchmark.warehouses.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.warehouses.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runCashRegisters(array $counts): array
    {
        $user = User::factory()->make([
            'id' => 100_003,
            'email' => 'bench-cash-100003@local.test',
        ]);
        $currency = Currency::make([
            'code' => 'TMT',
            'name' => 'Turkmenistan manat',
            'symbol' => 'T',
            'exchange_rate' => 3.5,
            'is_default' => true,
            'status' => 'active',
            'is_report' => true,
            'company_id' => 1,
        ])->forceFill(['id' => 1]);

        $rows = [];
        foreach ($counts as $n) {
            $items = self::cashRegisterItems($n, $user, $currency);
            $full = self::measureResolvedPayload(fn () => CashRegisterResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => CashRegisterReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('cash_registers', $n, $full, $reference);
            self::emitTelemetry('benchmark.cash_registers.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.cash_registers.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runDepartments(array $counts): array
    {
        $company = Company::make(['name' => 'Bench Co'])->forceFill(['id' => 1]);
        $head = User::factory()->make(['id' => 200_001, 'email' => 'bench-head@local.test']);
        $deputy = User::factory()->make(['id' => 200_002, 'email' => 'bench-deputy@local.test']);

        $rows = [];
        foreach ($counts as $n) {
            $items = self::departmentItems($n, $company, $head, $deputy);
            $full = self::measureResolvedPayload(fn () => DepartmentResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => DepartmentReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('departments', $n, $full, $reference);
            self::emitTelemetry('benchmark.departments.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.departments.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runMessageTemplates(array $counts): array
    {
        $company = Company::make(['name' => 'Bench Co'])->forceFill(['id' => 1]);
        $creator = User::factory()->make(['id' => 200_003, 'email' => 'bench-tpl-creator@local.test']);
        $heavyContent = str_repeat('<p>Bench template body.</p>', 80);

        $rows = [];
        foreach ($counts as $n) {
            $items = self::messageTemplateItems($n, $company, $creator, $heavyContent);
            $full = self::measureResolvedPayload(fn () => MessageTemplateResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => MessageTemplateReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('message_templates', $n, $full, $reference);
            self::emitTelemetry('benchmark.message_templates.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.message_templates.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runHolidays(array $counts): array
    {
        $company = Company::make(['name' => 'Bench Co'])->forceFill(['id' => 1]);

        $rows = [];
        foreach ($counts as $n) {
            $items = self::companyHolidayItems($n, $company);
            $full = self::measureResolvedPayload(fn () => HolidayResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => HolidayReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('holidays', $n, $full, $reference);
            self::emitTelemetry('benchmark.holidays.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.holidays.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runTransactionTemplates(array $counts): array
    {
        $currency = Currency::make([
            'code' => 'TMT',
            'name' => 'Turkmenistan manat',
            'symbol' => 'T',
            'exchange_rate' => 3.5,
            'is_default' => true,
            'status' => 'active',
            'is_report' => true,
            'company_id' => 1,
        ])->forceFill(['id' => 1]);
        $creator = User::factory()->make(['id' => 200_010, 'email' => 'bench-tpl-user@local.test', 'name' => 'A', 'surname' => 'B']);
        $cashRegister = CashRegister::make([
            'id' => 1,
            'name' => 'Bench cash',
            'balance' => 0,
            'currency_id' => 1,
            'company_id' => 1,
            'is_cash' => true,
            'is_working_minus' => false,
            'participates_in_transfers' => true,
            'icon' => 'fa-solid fa-cash-register',
        ]);
        $cashRegister->exists = true;
        $cashRegister->syncOriginal();
        $cashRegister->setRelation('currency', $currency);
        $category = TransactionCategory::make([
            'id' => 1,
            'name' => 'Bench category',
            'type' => 0,
            'creator_id' => $creator->id,
            'parent_id' => null,
        ]);
        $category->exists = true;
        $category->syncOriginal();
        $project = Project::make(['id' => 1, 'name' => 'Bench project', 'company_id' => 1]);
        $project->exists = true;
        $project->syncOriginal();
        $client = Client::make([
            'id' => 1,
            'client_type' => 'individual',
            'first_name' => 'Ivan',
            'last_name' => 'Ivanov',
            'patronymic' => null,
            'balance' => 0,
            'is_supplier' => false,
            'is_conflict' => false,
            'position' => null,
            'company_id' => 1,
        ]);
        $client->exists = true;
        $client->syncOriginal();

        $rows = [];
        foreach ($counts as $n) {
            $items = self::templateItems($n, $currency, $creator, $cashRegister, $category, $project, $client);
            $full = self::measureResolvedPayload(fn () => TransactionTemplateResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => TransactionTemplateReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('transaction_templates', $n, $full, $reference);
            self::emitTelemetry('benchmark.transaction_templates.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.transaction_templates.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runLeaves(array $counts): array
    {
        $company = Company::make(['name' => 'Bench Co'])->forceFill(['id' => 1]);
        $leaveType = LeaveType::make([
            'id' => 1,
            'name' => 'Annual',
            'color' => '#00AA00',
            'is_penalty' => false,
        ]);
        $leaveType->exists = true;
        $leaveType->syncOriginal();
        $user = User::factory()->make(['id' => 200_011, 'email' => 'bench-leave-user@local.test', 'name' => 'U', 'surname' => 'V']);

        $rows = [];
        foreach ($counts as $n) {
            $items = self::leaveItems($n, $company, $leaveType, $user);
            $full = self::measureResolvedPayload(fn () => LeaveResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => LeaveReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('leaves', $n, $full, $reference);
            self::emitTelemetry('benchmark.leaves.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.leaves.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runProjects(array $counts): array
    {
        $currency = Currency::make([
            'code' => 'TMT',
            'name' => 'Turkmenistan manat',
            'symbol' => 'T',
            'exchange_rate' => 3.5,
            'is_default' => true,
            'status' => 'active',
            'is_report' => true,
            'company_id' => 1,
        ])->forceFill(['id' => 1]);
        $client = Client::make([
            'id' => 1,
            'client_type' => 'individual',
            'first_name' => 'Ivan',
            'last_name' => 'Ivanov',
            'patronymic' => null,
            'balance' => 0,
            'is_supplier' => false,
            'is_conflict' => false,
            'position' => null,
            'company_id' => 1,
        ]);
        $client->exists = true;
        $client->syncOriginal();
        $status = ProjectStatus::make([
            'id' => 1,
            'name' => 'Active',
            'color' => '#00AA00',
            'is_visible' => true,
            'kanban_outcome' => null,
            'creator_id' => 1,
        ]);
        $status->exists = true;
        $status->syncOriginal();
        $creator = User::factory()->make(['id' => 200_020, 'email' => 'bench-proj-creator@local.test', 'name' => 'C', 'surname' => 'R', 'photo' => null]);
        $member = User::factory()->make(['id' => 200_021, 'email' => 'bench-proj-member@local.test', 'name' => 'M', 'surname' => 'B']);

        $rows = [];
        foreach ($counts as $n) {
            $items = self::projectItems($n, $currency, $client, $status, $creator, $member);
            $full = self::measureResolvedPayload(fn () => ProjectResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => ProjectReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('projects', $n, $full, $reference);
            self::emitTelemetry('benchmark.projects.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.projects.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  list<int>  $counts
     * @return list<array<string, mixed>>
     */
    public static function runTasks(array $counts): array
    {
        $taskStatus = TaskStatus::make([
            'id' => 1,
            'name' => 'Open',
            'color' => '#336699',
            'kanban_outcome' => null,
            'creator_id' => 1,
        ]);
        $taskStatus->exists = true;
        $taskStatus->syncOriginal();
        $creator = User::factory()->make(['id' => 200_030, 'email' => 'bench-task-creator@local.test', 'name' => 'A', 'surname' => 'B', 'position' => null, 'photo' => null]);
        $supervisor = User::factory()->make(['id' => 200_031, 'email' => 'bench-task-sup@local.test', 'name' => 'S', 'surname' => 'U', 'position' => 'Lead', 'photo' => null]);
        $executor = User::factory()->make(['id' => 200_032, 'email' => 'bench-task-ex@local.test', 'name' => 'E', 'surname' => 'X', 'position' => 'Dev', 'photo' => null]);
        foreach ([$creator, $supervisor, $executor] as $benchUser) {
            $benchUser->exists = true;
            $benchUser->syncOriginal();
        }
        $project = Project::make(['id' => 1, 'name' => 'Bench project', 'company_id' => 1]);
        $project->exists = true;
        $project->syncOriginal();

        $rows = [];
        foreach ($counts as $n) {
            $items = self::taskItems($n, $taskStatus, $creator, $supervisor, $executor, $project);
            $full = self::measureResolvedPayload(fn () => TaskResource::collection($items)->resolve());
            $reference = self::measureResolvedPayload(fn () => TaskReferenceResource::collection($items)->resolve());
            $rows[] = self::metricsRow('tasks', $n, $full, $reference);
            self::emitTelemetry('benchmark.tasks.full', $n, $full['bytes']);
            self::emitTelemetry('benchmark.tasks.reference', $n, $reference['bytes']);
        }

        return $rows;
    }

    /**
     * @param  array{bytes:int,ms:float}  $full
     * @param  array{bytes:int,ms:float}  $reference
     * @return array<string, mixed>
     */
    private static function metricsRow(string $entity, int $count, array $full, array $reference): array
    {
        $ratio = $full['bytes'] > 0 ? round($reference['bytes'] / $full['bytes'], 6) : 0.0;
        $bytesSavingPercent = round((1 - $ratio) * 100, 2);
        $timeSavedSeconds = round(($full['ms'] - $reference['ms']) / 1000, 4);

        return [
            'entity' => $entity,
            'count' => $count,
            'full_json_bytes' => $full['bytes'],
            'reference_json_bytes' => $reference['bytes'],
            'reference_to_full_ratio' => $ratio,
            'bytes_saving_percent' => $bytesSavingPercent,
            'time_saved_seconds' => $timeSavedSeconds,
            'full_resolve_and_encode_ms' => $full['ms'],
            'reference_resolve_and_encode_ms' => $reference['ms'],
        ];
    }

    /**
     * @param  callable(): array<int|string, mixed>  $resolve
     * @return array{bytes:int,ms:float}
     */
    private static function measureResolvedPayload(callable $resolve): array
    {
        $t0 = hrtime(true);
        $data = $resolve();
        $bytes = ReferencePayloadBudget::jsonEncodedByteLength(['data' => $data]);
        $ms = (hrtime(true) - $t0) / 1e6;

        return ['bytes' => $bytes, 'ms' => $ms];
    }

    /**
     * @return void
     */
    private static function emitTelemetry(string $label, int $count, int $bytes): void
    {
        ReferenceTelemetry::maybeLogReferenceRequest($label.'.'.$count, $bytes);
    }

    /**
     * @return EloquentCollection<int, Warehouse>
     */
    private static function warehouseItems(int $n, User $userA, User $userB): EloquentCollection
    {
        $list = [];
        foreach (range(1, $n) as $i) {
            $warehouse = Warehouse::make([
                'id' => $i,
                'name' => 'Warehouse '.$i,
                'company_id' => 1,
            ]);
            $warehouse->exists = true;
            $warehouse->syncOriginal();
            $warehouse->setRelation('users', collect([$userA, $userB]));
            $list[] = $warehouse;
        }

        return new EloquentCollection($list);
    }

    /**
     * @return EloquentCollection<int, CashRegister>
     */
    private static function cashRegisterItems(int $n, User $user, Currency $currency): EloquentCollection
    {
        $list = [];
        foreach (range(1, $n) as $i) {
            $register = CashRegister::make([
                'id' => $i,
                'name' => 'Cash register '.$i,
                'balance' => 123.45,
                'currency_id' => 1,
                'company_id' => 1,
                'is_cash' => true,
                'is_working_minus' => false,
                'icon' => 'fa-solid fa-cash-register',
                'color' => null,
            ]);
            $register->exists = true;
            $register->syncOriginal();
            $register->setRelation('currency', $currency);
            $register->setRelation('users', collect([$user]));
            $list[] = $register;
        }

        return new EloquentCollection($list);
    }

    /**
     * @return EloquentCollection<int, Department>
     */
    private static function departmentItems(int $n, Company $company, User $head, User $deputy): EloquentCollection
    {
        $list = [];
        foreach (range(1, $n) as $i) {
            $department = Department::make([
                'id' => $i,
                'title' => 'Department '.$i,
                'description' => 'Description '.$i,
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
            $list[] = $department;
        }

        return new EloquentCollection($list);
    }

    /**
     * @return EloquentCollection<int, MessageTemplate>
     */
    private static function messageTemplateItems(int $n, Company $company, User $creator, string $content): EloquentCollection
    {
        $list = [];
        foreach (range(1, $n) as $i) {
            $template = MessageTemplate::make([
                'id' => $i,
                'type' => 'birthday',
                'name' => 'Template '.$i,
                'content' => $content,
                'company_id' => $company->id,
                'creator_id' => $creator->id,
                'is_active' => true,
            ]);
            $template->exists = true;
            $template->syncOriginal();
            $template->setRelation('creator', $creator);
            $template->setRelation('company', $company);
            $list[] = $template;
        }

        return new EloquentCollection($list);
    }

    /**
     * @return EloquentCollection<int, Holiday>
     */
    private static function companyHolidayItems(int $n, Company $company): EloquentCollection
    {
        $list = [];
        foreach (range(1, $n) as $i) {
            $holiday = Holiday::make([
                'id' => $i,
                'company_id' => $company->id,
                'name' => 'Holiday '.$i,
                'date' => '2026-01-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT),
                'end_date' => null,
                'is_recurring' => true,
                'color' => '#FF5733',
                'icon' => 'fa-solid fa-calendar-day',
            ]);
            $holiday->exists = true;
            $holiday->syncOriginal();
            $list[] = $holiday;
        }

        return new EloquentCollection($list);
    }

    /**
     * @return EloquentCollection<int, Template>
     */
    private static function templateItems(
        int $n,
        Currency $currency,
        User $creator,
        CashRegister $cashRegister,
        TransactionCategory $category,
        Project $project,
        Client $client
    ): EloquentCollection {
        $longNote = str_repeat('Bench template note line. ', 24);
        $list = [];
        foreach (range(1, $n) as $i) {
            $template = Template::make([
                'id' => $i,
                'cash_id' => $cashRegister->id,
                'name' => 'Template '.$i,
                'icon' => 'fas fa-star',
                'amount' => 100.5,
                'currency_id' => $currency->id,
                'type' => false,
                'category_id' => $category->id,
                'note' => $longNote,
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
            $list[] = $template;
        }

        return new EloquentCollection($list);
    }

    /**
     * @return EloquentCollection<int, Leave>
     */
    private static function leaveItems(int $n, Company $company, LeaveType $leaveType, User $user): EloquentCollection
    {
        $list = [];
        foreach (range(1, $n) as $i) {
            $leave = Leave::make([
                'id' => $i,
                'leave_type_id' => $leaveType->id,
                'user_id' => $user->id,
                'company_id' => $company->id,
                'comment' => 'Comment '.$i,
                'date_from' => '2026-03-01 09:00:00',
                'date_to' => '2026-03-05 18:00:00',
            ]);
            $leave->exists = true;
            $leave->syncOriginal();
            $leave->setRelation('leaveType', $leaveType);
            $leave->setRelation('user', $user);
            $list[] = $leave;
        }

        return new EloquentCollection($list);
    }

    /**
     * @return EloquentCollection<int, Project>
     */
    private static function projectItems(
        int $n,
        Currency $currency,
        Client $client,
        ProjectStatus $status,
        User $creator,
        User $member
    ): EloquentCollection {
        $heavyDescription = str_repeat('Bench project description block. ', 30);
        $list = [];
        foreach (range(1, $n) as $i) {
            $project = Project::make([
                'id' => $i,
                'name' => 'Project '.$i,
                'company_id' => 1,
                'client_id' => $client->id,
                'currency_id' => $currency->id,
                'status_id' => $status->id,
                'creator_id' => $creator->id,
                'budget' => 5000.5,
                'date' => '2026-02-01',
                'description' => $heavyDescription,
            ]);
            $project->exists = true;
            $project->syncOriginal();
            $project->setRelation('client', $client);
            $project->setRelation('currency', $currency);
            $project->setRelation('status', $status);
            $project->setRelation('creator', $creator);
            $project->setRelation('users', collect([$member]));
            $list[] = $project;
        }

        return new EloquentCollection($list);
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    private static function taskItems(
        int $n,
        TaskStatus $taskStatus,
        User $creator,
        User $supervisor,
        User $executor,
        Project $project
    ): EloquentCollection {
        $longDescription = str_repeat('Bench task description paragraph. ', 35);
        $heavyFiles = [
            ['name' => 'spec.pdf', 'path' => 'tasks/1/spec.pdf', 'size' => 180_000, 'mime_type' => 'application/pdf', 'uploaded_at' => '2026-01-05 10:00:00'],
        ];
        $heavyComments = [['id' => 1, 'body' => 'Comment text', 'user_id' => 1]];
        $heavyChecklist = [['text' => 'Step one', 'done' => false], ['text' => 'Step two', 'done' => true]];
        $list = [];
        foreach (range(1, $n) as $i) {
            $task = Task::make([
                'id' => $i,
                'title' => 'Task '.$i,
                'description' => $longDescription,
                'company_id' => 1,
                'status_id' => $taskStatus->id,
                'creator_id' => $creator->id,
                'supervisor_id' => $supervisor->id,
                'executor_id' => $executor->id,
                'project_id' => $project->id,
                'priority' => TaskPriority::HIGH,
                'complexity' => TaskComplexity::COMPLEX,
                'deadline' => '2026-05-01 15:30:00',
                'files' => $heavyFiles,
                'comments' => $heavyComments,
                'checklist' => $heavyChecklist,
            ]);
            $task->exists = true;
            $task->syncOriginal();
            $task->setRelation('status', $taskStatus);
            $task->setRelation('creator', $creator);
            $task->setRelation('supervisor', $supervisor);
            $task->setRelation('executor', $executor);
            $task->setRelation('project', $project);
            $list[] = $task;
        }

        return new EloquentCollection($list);
    }
}
