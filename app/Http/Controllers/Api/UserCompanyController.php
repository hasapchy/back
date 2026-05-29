<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Enums\TokenClient;
use App\Models\Sanctum\PersonalAccessToken;
use App\Services\CacheService;
use App\Services\MobileSanctumTokenService;
use App\Support\ResolvedCompany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/**
 * @group Пользователи
 * @subgroup Контекст компании
 */
class UserCompanyController extends BaseController
{
    private ?bool $hasTransactionCategoryBindingsTable = null;

    public function __construct(
        private readonly MobileSanctumTokenService $mobileSanctumTokenService
    ) {
    }

    /**
     * Получить текущую компанию пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrentCompany(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $selectedCompanyId = $this->getCurrentCompanyId() ?? $request->input('company_id');
        if (! $selectedCompanyId) {
            return $this->errorResponse('Company context missing', 409);
        }

        $query = Company::where('id', $selectedCompanyId)
            ->whereHas('users', function ($query) use ($user) {
                $query->where('users.id', $user->id);
            });

        if ($this->canLoadTransactionCategoryBindings()) {
            $query->with(['transactionCategoryBindings:company_id,binding_key,transaction_category_id']);
        }

        $company = $query->first();

        if (! $company) {
            return $this->errorResponse('Company not found or access denied', 404);
        }

        if ($request->hasSession() && $request->session()->isStarted()) {
            $request->session()->put(ResolvedCompany::SESSION_KEY, (int) $company->id);
        }

        return $this->successResponse((new CompanyResource($company))->resolve());
    }

    /**
     * Установить текущую компанию пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function setCurrentCompany(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $companyId = $request->company_id;

        if (! $companyId) {
            $company = $user->companies()->first();
            if (! $company) {
                return $this->errorResponse('No companies available', 404);
            }
            $companyId = $company->id;
        } else {
            $company = $user->companies()->where('companies.id', $companyId)->first();

            if (! $company) {
                return $this->errorResponse('Company not found or access denied', 404);
            }
        }

        CacheService::invalidateCurrenciesCache();

        $companyIdInt = (int) $companyId;

        if ($request->hasSession() && $request->session()->isStarted()) {
            $request->session()->put(ResolvedCompany::SESSION_KEY, $companyIdInt);
        }

        if (! EnsureFrontendRequestsAreStateful::fromFrontend($request)) {
            $current = $user->currentAccessToken();
            $fingerprint = $current instanceof PersonalAccessToken ? $current->device_fingerprint : null;
            $query = $user->tokensForClient(TokenClient::Mobile);
            if ($fingerprint !== null && $fingerprint !== '') {
                $query->where('device_fingerprint', $fingerprint);
            }
            $query->delete();

            $remember = $request->boolean('remember');
            $tokens = $this->mobileSanctumTokenService->issueTokenPair($user, $remember, $companyIdInt);

            if ($this->canLoadTransactionCategoryBindings()) {
                $company->loadMissing(['transactionCategoryBindings:company_id,binding_key,transaction_category_id']);
            }

            return $this->successResponse(array_merge(
                (new CompanyResource($company))->resolve(),
                $tokens
            ), 'Company selected successfully');
        }

        if ($this->canLoadTransactionCategoryBindings()) {
            $company->loadMissing(['transactionCategoryBindings:company_id,binding_key,transaction_category_id']);
        }

        return $this->successResponse(
            (new CompanyResource($company))->resolve(),
            'Company selected successfully'
        );
    }

    /**
     * Получить список компаний пользователя
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserCompanies()
    {
        $user = $this->getAuthenticatedUser();
        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        $query = $user->companies()->select(
            'companies.id',
            'companies.name',
            'companies.full_name',
            'companies.logo',
            'companies.address',
            'companies.phone',
            'companies.registration_number',
            'companies.email',
            'companies.warehouse_number',
            'companies.show_deleted_transactions',
            'companies.rounding_decimals',
            'companies.display_decimals',
            'companies.rounding_enabled',
            'companies.rounding_direction',
            'companies.rounding_custom_threshold',
            'companies.rounding_orders_enabled',
            'companies.rounding_contracts_enabled',
            'companies.rounding_warehouse_enabled',
            'companies.rounding_quantity_decimals',
            'companies.rounding_quantity_enabled',
            'companies.rounding_quantity_direction',
            'companies.rounding_quantity_custom_threshold',
            'companies.skip_project_order_balance',
            'companies.work_schedule',
            'companies.created_at',
            'companies.updated_at'
        );

        if ($this->canLoadTransactionCategoryBindings()) {
            $query->with(['transactionCategoryBindings:company_id,binding_key,transaction_category_id']);
        }

        $companies = $query->get();

        return $this->successResponse(CompanyResource::collection($companies)->resolve());
    }

    /**
     * Проверяет доступность таблицы привязок категорий транзакций.
     *
     * @return bool
     */
    private function canLoadTransactionCategoryBindings(): bool
    {
        if ($this->hasTransactionCategoryBindingsTable !== null) {
            return $this->hasTransactionCategoryBindingsTable;
        }

        $this->hasTransactionCategoryBindingsTable = Schema::hasTable('transaction_category_bindings')
            || Schema::hasTable('company_transaction_category_bindings');

        return $this->hasTransactionCategoryBindingsTable;
    }

}
