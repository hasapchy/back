<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Models\SalaryMonthlyReport;
use App\Repositories\RolesRepository;
use App\Services\CacheService;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use App\Http\Requests\AccrueSalariesRequest;
use App\Services\SalaryAccrualService;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

/**
 * Контроллер для работы с компаниями
 */
class CompaniesController extends BaseController
{
    /**
     * Получить список компаний с пагинацией
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $companies = Company::select(['id', 'name', 'logo', 'show_deleted_transactions', 'rounding_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold', 'rounding_quantity_decimals', 'rounding_quantity_enabled', 'rounding_quantity_direction', 'rounding_quantity_custom_threshold', 'skip_project_order_balance', 'work_schedule', 'created_at', 'updated_at'])
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
     * Создать новую компанию
     *
     * @param StoreCompanyRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(StoreCompanyRequest $request)
    {
        $data = $request->validated();

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('companies', 'public');
        }

        $company = Company::create($data);

        $rolesRepository = app(RolesRepository::class);
        $rolesRepository->createDefaultRolesForCompany($company->id);

        return $this->successResponse(new CompanyResource($company));
    }

    /**
     * Обновить компанию
     *
     * @param UpdateCompanyRequest $request
     * @param int $id ID компании
     * @return \Illuminate\Http\JsonResponse
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

        $company = $company->fresh();

        return $this->successResponse(new CompanyResource($company));
    }

    /**
     * Удалить компанию
     *
     * @param int $id ID компании
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $company = Company::findOrFail($id);
        $company->delete();

        return $this->successResponse(null, 'Company deleted');
    }

    /**
     * Массовое начисление зарплат для всех сотрудников компании
     *
     * @param AccrueSalariesRequest $request
     * @param int $id ID компании
     * @return \Illuminate\Http\JsonResponse
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
            $paymentType = (bool)$validatedData['payment_type'];
            $items = $validatedData['items'] ?? null;

            if (is_array($items) && !$this->hasPermission('settings_client_balance_view')) {
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
                    'errors' => count($results['errors'])
                ]
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Компания не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при начислении зарплат: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Массовая выплата зарплат с записью батча в отчёт (salary_monthly_reports).
     *
     * @param  AccrueSalariesRequest  $request
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

            if (is_array($items) && ! $this->hasPermission('settings_client_balance_view')) {
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Компания не найдена', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при выплате зарплат: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Проверить существующие начисления зарплат за месяц
     *
     * @param Request $request
     * @param int $id ID компании
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkExistingSalaries(Request $request, $id)
    {
        try {
            $company = Company::findOrFail($id);

            $request->validate([
                'date' => 'required|date',
                'creator_ids' => 'required|array|min:1',
                'creator_ids.*' => 'integer|exists:users,id',
                'payment_type' => 'nullable|boolean',
            ]);

            $date = $request->input('date');
            $userIds = $request->input('creator_ids');
            $paymentType = $request->filled('payment_type') ? (int) $request->input('payment_type') : null;
            $monthStart = Carbon::parse($date)->startOfMonth()->toDateString();
            $monthEnd = Carbon::parse($date)->endOfMonth()->toDateString();

            $linesQuery = DB::table('salary_monthly_report_lines as lines')
                ->join('salary_monthly_reports as reports', 'reports.id', '=', 'lines.salary_monthly_report_id')
                ->where('reports.company_id', (int) $company->id)
                ->where('reports.type', SalaryMonthlyReport::TYPE_ACCRUAL)
                ->whereBetween('reports.date', [$monthStart, $monthEnd])
                ->whereIn('lines.employee_id', $userIds);

            if ($paymentType !== null) {
                $linesQuery
                    ->join('transactions', 'transactions.id', '=', 'lines.transaction_id')
                    ->join('client_balances', 'client_balances.id', '=', 'transactions.client_balance_id')
                    ->where('client_balances.type', $paymentType);
            }

            $affectedUserIds = $linesQuery
                ->distinct()
                ->pluck('lines.employee_id')
                ->map(static fn ($id) => (int) $id)
                ->values()
                ->all();

            $affectedUsers = User::query()
                ->whereIn('id', $affectedUserIds)
                ->get(['id', 'name', 'surname'])
                ->map(static function (User $user): array {
                    $fullName = trim(($user->name ?? '') . ' ' . ($user->surname ?? ''));

                    return [
                        'creator_id' => (int) $user->id,
                        'name' => $fullName !== '' ? $fullName : ('#' . $user->id),
                    ];
                })
                ->values()
                ->all();

            $checkResult = [
                'has_existing' => count($affectedUserIds) > 0,
                'affected_users' => $affectedUsers,
            ];

            return $this->successResponse($checkResult);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Компания не найдена', 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при проверке начислений: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Предпросмотр начисления зарплаты за месяц
     *
     * @param Request $request
     * @param int $id ID компании
     * @return \Illuminate\Http\JsonResponse
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

            $includeBalances = $this->hasPermission('settings_client_balance_view');
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
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Компания не найдена', 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при получении предпросмотра начисления: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Зарплаты за месяц: без batch_id — список батчей (salary_monthly_reports); с batch_id — батч и строки (lines).
     *
     * @param Request $request
     * @param int|string $id
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
                ? $this->listAllSalaryReportBatches($companyId)
                : $this->salaryAccrualService()->listSalaryReportBatches($companyId, $month);

            return $this->successResponse([
                'month' => $month,
                'items' => $items,
                'synced_at' => null,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Компания не найдена', 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->validationErrorResponse($e->validator);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка при загрузке данных по зарплатам: ' . $e->getMessage(), 500);
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
        if (! $this->hasPermission('transactions_delete_all') && ! $this->hasPermission('transactions_delete')) {
            return $this->errorResponse('Нет права удалять проводки', 403);
        }

        try {
            $company = Company::findOrFail($id);
            $this->salaryAccrualService()->deleteSalaryMonthlyReportBatch((int) $company->id, (int) $batchId);

            return $this->successResponse(null, 'Операция удалена');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->errorResponse('Не найдено', 404);
        } catch (\Exception $e) {
            return $this->errorResponse('Ошибка удаления: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listAllSalaryReportBatches(int $companyId): array
    {
        $reports = SalaryMonthlyReport::query()
            ->where('company_id', $companyId)
            ->withCount('lines')
            ->with(['lines' => fn ($q) => $q->select(['id', 'salary_monthly_report_id', 'amount', 'currency_id', 'transaction_id'])->with('currency:id,symbol')])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();

        $out = [];
        foreach ($reports as $r) {
            $out[] = [
                'id' => $r->id,
                'type' => $r->type,
                'date' => $r->date->format('Y-m-d'),
                'created_at' => $r->created_at->toIso8601String(),
                'line_count' => $r->lines_count,
                'totals_display' => $this->salaryLinesTotalsDisplay($r->lines),
                'payment_type' => $r->payment_type !== null ? (int) $r->payment_type : null,
            ];
        }

        return $out;
    }

    /**
     * @param iterable<int, \App\Models\SalaryMonthlyReportLine> $lines
     */
    private function salaryLinesTotalsDisplay(iterable $lines): string
    {
        $totalsBySym = [];
        foreach ($lines as $line) {
            $sym = $line->currency?->symbol ?? '';
            $totalsBySym[$sym] = ($totalsBySym[$sym] ?? 0) + (float) $line->amount;
        }
        $parts = [];
        foreach ($totalsBySym as $sym => $amount) {
            $parts[] = trim(number_format($amount, 2, '.', '') . ($sym !== '' ? ' ' . $sym : ''));
        }

        return implode(' · ', $parts);
    }

    /**
     * @return SalaryAccrualService
     */
    private function salaryAccrualService(): SalaryAccrualService
    {
        return app(SalaryAccrualService::class);
    }
}
