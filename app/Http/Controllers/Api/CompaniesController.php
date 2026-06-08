<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\AccrueSalariesRequest;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\TransactionCategoryBinding;
use App\Models\TransactionCategory;
use App\Repositories\RolesRepository;
use App\Services\SalaryAccrualService;
use App\Services\TransactionCategoryBindingDefaultsService;
use App\Support\TransactionCategoryBindingKeys;
use App\Support\TransactionCategoryTypeGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Контроллер для работы с компаниями
 */
/**
 * @group Компании
 */
class CompaniesController extends BaseController
{
    /**
     * Список компаний
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $companies = Company::select([
            'id', 'name', 'full_name', 'logo',
            'address', 'phone', 'registration_number', 'email', 'warehouse_number',
            'show_deleted_transactions', 'display_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold',
            'rounding_orders_enabled', 'rounding_orders_decimals',
            'rounding_contracts_enabled', 'rounding_contracts_decimals',
            'rounding_warehouse_enabled', 'rounding_warehouse_decimals',
            'rounding_transactions_enabled', 'rounding_transactions_decimals',
            'rounding_quantity_decimals', 'rounding_quantity_enabled', 'rounding_quantity_direction', 'rounding_quantity_custom_threshold',
            'skip_project_order_balance', 'work_schedule', 'ui_theme', 'created_at', 'updated_at',
        ])
            ->with(['transactionCategoryBindings:company_id,binding_key,transaction_category_id'])
            ->orderBy('name')
            ->paginate($perPage);

        return $this->successResponse([
            'items' => CompanyResource::collection($companies->items())->resolve(),
            'meta' => [
                'current_page' => $companies->currentPage(),
                'last_page' => $companies->lastPage(),
                'per_page' => $companies->perPage(),
                'total' => $companies->total(),
            ],
        ]);
    }

    /**
     * Создать компанию
     *
     * @return JsonResponse
     */
    public function store(StoreCompanyRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('companies', 'public');
        }

        $company = Company::create($data);
        $this->syncTransactionCategoryBindings($company, $request->input('transaction_category_bindings'));
        app(TransactionCategoryBindingDefaultsService::class)->seedMissingForCompany((int) $company->id);
        $company->load(['transactionCategoryBindings:company_id,binding_key,transaction_category_id']);

        $rolesRepository = app(RolesRepository::class);
        $rolesRepository->createDefaultRolesForCompany($company->id);

        return $this->successResponse((new CompanyResource($company))->resolve());
    }

    /**
     * Обновить компанию
     *
     * @param  int  $id  ID компании
     * @return JsonResponse
     */
    public function update(UpdateCompanyRequest $request, $id)
    {
        $company = Company::findOrFail($id);

        $data = $request->validated();

        if ($request->hasFile('logo')) {
            if ($company->logo && $company->logo !== 'logo.png') {
                Storage::disk('public')->delete($company->logo);
            }
            $data['logo'] = $request->file('logo')->store('companies', 'public');
        }

        $company->update($data);
        $this->syncTransactionCategoryBindings($company, $request->input('transaction_category_bindings'));
        app(TransactionCategoryBindingDefaultsService::class)->seedMissingForCompany((int) $company->id);

        $company = $company->fresh();
        $company->load(['transactionCategoryBindings:company_id,binding_key,transaction_category_id']);

        return $this->successResponse((new CompanyResource($company))->resolve());
    }

    /**
     * Удалить компанию
     *
     * @param  int  $id  ID компании
     * @return JsonResponse
     */
    public function destroy($id)
    {
        $company = Company::findOrFail($id);
        $company->delete();

        return $this->successResponse(null, __('Company deleted'));
    }

    /**
     * Массовое начисление зарплат для всех сотрудников компании
     *
     * @param  int  $id  ID компании
     * @return JsonResponse
     */
    public function accrueSalaries(AccrueSalariesRequest $request, $id)
    {
        try {
            $company = Company::findOrFail($id);

            $validatedData = $request->validated();
            $date = $validatedData['date'];
            $cashId = $validatedData['cash_id'];
            $note = $validatedData['note'] ?? null;
            $userIds = $validatedData['creator_ids'];
            $paymentType = (bool) $validatedData['payment_type'];
            $items = $validatedData['items'] ?? null;

            if (is_array($items) && ! $this->requireAuthenticatedUser()->can('settings_client_balance_view')) {
                $items = array_map(static function ($row) {
                    unset($row['client_balance_id']);

                    return $row;
                }, $items);
            }

            $results = $this->salaryAccrualService()->accrueSalariesForCompany(
                $company->id,
                $date,
                $cashId,
                $note,
                $userIds,
                $paymentType,
                $items
            );

            return $this->successResponse([
                'message' => 'Начисление зарплат завершено',
                'results' => $results,
                'summary' => [
                    'success' => count($results['success']),
                    'skipped' => count($results['skipped']),
                    'errors' => count($results['errors']),
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(__('Компания не найдена'), 404);
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при начислении зарплат: ').$e->getMessage(), 500);
        }
    }

    /**
     * Массовая выплата зарплат с записью батча в отчёт (salary_monthly_reports).
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function paySalaries(AccrueSalariesRequest $request, $id)
    {
        try {
            $company = Company::findOrFail($id);

            $validatedData = $request->validated();
            $date = $validatedData['date'];
            $cashId = $validatedData['cash_id'];
            $note = $validatedData['note'] ?? null;
            $userIds = $validatedData['creator_ids'];
            $paymentType = (bool) $validatedData['payment_type'];
            $items = $validatedData['items'] ?? null;

            if (is_array($items) && ! $this->requireAuthenticatedUser()->can('settings_client_balance_view')) {
                $items = array_map(static function ($row) {
                    unset($row['client_balance_id']);

                    return $row;
                }, $items);
            }

            $results = $this->salaryAccrualService()->paySalariesForCompany(
                $company->id,
                $date,
                $cashId,
                $note,
                $userIds,
                $paymentType,
                $items
            );

            return $this->successResponse([
                'message' => 'Выплата зарплат завершена',
                'results' => $results,
                'summary' => [
                    'success' => count($results['success']),
                    'skipped' => count($results['skipped']),
                    'errors' => count($results['errors']),
                ],
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(__('Компания не найдена'), 404);
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при выплате зарплат: ').$e->getMessage(), 500);
        }
    }

    /**
     * Проверить существующие начисления зарплат за месяц
     *
     * @param  int  $id  ID компании
     * @return JsonResponse
     */
    public function checkExistingSalaries(Request $request, $id)
    {
        try {
            Company::findOrFail($id);

            $request->validate([
                'date' => 'required|date',
                'creator_ids' => 'required|array|min:1',
                'creator_ids.*' => 'integer|exists:users,id',
                'payment_type' => 'nullable|boolean',
            ]);

            return $this->successResponse([
                'has_existing' => false,
                'affected_users' => [],
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(__('Компания не найдена'), 404);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при проверке начислений: ').$e->getMessage(), 500);
        }
    }

    /**
     * Предпросмотр начисления зарплаты за месяц
     *
     * @param  int  $id  ID компании
     * @return JsonResponse
     */
    public function salaryAccrualPreview(Request $request, $id)
    {
        try {
            $company = Company::findOrFail($id);

            $request->validate([
                'date' => 'required|date',
                'creator_ids' => 'required|array|min:1',
                'creator_ids.*' => 'integer|exists:users,id',
                'payment_type' => 'nullable|boolean',
                'currency_id' => 'nullable|integer|exists:currencies,id',
                'apply_transaction_adjustments' => 'nullable|boolean',
            ]);

            $date = $request->input('date');
            $userIds = $request->input('creator_ids');
            $paymentType = (bool) $request->input('payment_type', true);
            $currencyId = $request->filled('currency_id') ? (int) $request->input('currency_id') : null;
            $applyTransactionAdjustments = $request->boolean('apply_transaction_adjustments', true);

            $includeBalances = $this->requireAuthenticatedUser()->can('settings_client_balance_view');
            $preview = $this->salaryAccrualService()->getAccrualPreview(
                $company->id,
                $date,
                $userIds,
                $paymentType,
                $includeBalances,
                $currencyId,
                $applyTransactionAdjustments
            );

            return $this->successResponse($preview);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(__('Компания не найдена'), 404);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при получении предпросмотра начисления: ').$e->getMessage(), 500);
        }
    }

    /**
     * Зарплаты за месяц: без batch_id — список батчей (salary_monthly_reports); с batch_id — батч и строки (lines).
     *
     * @param  int|string  $id
     * @return JsonResponse
     */
    public function salaryMonthlyReport(Request $request, $id)
    {
        try {
            $company = Company::findOrFail($id);

            $request->validate([
                'batch_id' => 'nullable|integer|min:1',
                'month' => 'required_without_all:batch_id,all|nullable|date_format:Y-m',
                'all' => 'nullable|boolean',
                'refresh' => 'nullable|boolean',
            ]);

            $companyId = (int) $company->id;

            if ($request->filled('batch_id')) {
                $batchId = (int) $request->input('batch_id');
                $data = $this->salaryAccrualService()->getSalaryReportBatch($companyId, $batchId);

                return $this->successResponse($data);
            }

            $all = $request->boolean('all', false);
            $month = (string) ($request->input('month') ?? '');
            $items = $all
                ? $this->salaryAccrualService()->listAllSalaryReportBatches($companyId)
                : $this->salaryAccrualService()->listSalaryReportBatches($companyId, $month);

            return $this->successResponse([
                'month' => $month,
                'items' => $items,
                'synced_at' => null,
            ]);
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(__('Компания не найдена'), 404);
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка при загрузке данных по зарплатам: ').$e->getMessage(), 500);
        }
    }

    /**
     * Удалить батч зарплаты (отчёт) и связанные проводки.
     *
     * @param  int|string  $id
     * @param  int|string  $batchId
     * @return JsonResponse
     */
    public function deleteSalaryMonthlyReportBatch($id, $batchId)
    {
        $u = $this->requireAuthenticatedUser();
        if (! $u->can('transactions_delete_all') && ! $u->can('transactions_delete')) {
            return $this->errorResponse(__('Нет права удалять проводки'), 403);
        }

        try {
            $company = Company::findOrFail($id);
            $this->salaryAccrualService()->deleteSalaryMonthlyReportBatch((int) $company->id, (int) $batchId);

            return $this->successResponse(null, __('Операция удалена'));
        } catch (ModelNotFoundException $e) {
            return $this->errorResponse(__('Не найдено'), 404);
        } catch (\Exception $e) {
            return $this->errorResponse(__('Ошибка удаления: ').$e->getMessage(), 500);
        }
    }

    private function salaryAccrualService(): SalaryAccrualService
    {
        return app(SalaryAccrualService::class);
    }

    /**
     * @param array<string, mixed>|null $bindings
     * @return void
     */
    private function syncTransactionCategoryBindings(Company $company, ?array $bindings): void
    {
        if ($bindings === null) {
            return;
        }

        $normalized = [];
        foreach ($bindings as $entryKey => $binding) {
            $key = '';
            $categoryId = 0;

            if (is_array($binding)) {
                $key = isset($binding['binding_key']) ? (string) $binding['binding_key'] : '';
                $categoryId = isset($binding['transaction_category_id']) ? (int) $binding['transaction_category_id'] : 0;
            } elseif (is_string($entryKey)) {
                $key = $entryKey;
                $categoryId = (int) $binding;
            }

            if ($key === '' || $categoryId <= 0) {
                continue;
            }

            if (! TransactionCategoryBindingKeys::has($key)) {
                continue;
            }

            TransactionCategoryTypeGuard::assertCategoryMatchesBindingKey($key, $categoryId);

            $normalized[$key] = $categoryId;
        }

        $requestedCategoryIds = array_values($normalized);
        $existingCategoryIds = $requestedCategoryIds === []
            ? []
            : TransactionCategory::query()
                ->whereIn('id', $requestedCategoryIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

        $normalized = array_filter(
            $normalized,
            fn ($categoryId) => in_array((int) $categoryId, $existingCategoryIds, true)
        );

        if ($normalized === []) {
            return;
        }

        TransactionCategoryBinding::query()
            ->where('company_id', $company->id)
            ->delete();

        $rows = [];
        $now = now();
        foreach ($normalized as $key => $categoryId) {
            $rows[] = [
                'company_id' => $company->id,
                'binding_key' => $key,
                'transaction_category_id' => $categoryId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TransactionCategoryBinding::query()->insert($rows);
    }
}
