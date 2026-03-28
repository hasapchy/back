<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CompanyResource;
use App\Models\Company;
use App\Services\CacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Контроллер для работы с компаниями пользователя
 */
class UserCompanyController extends BaseController
{
    /**
     * Получить текущую компанию пользователя
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCurrentCompany(Request $request)
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->errorResponse(null, 401);
        }

        $selectedCompanyId = $this->getCurrentCompanyId() ?? $request->input('company_id');

        if ($selectedCompanyId) {
            $company = Company::where('id', $selectedCompanyId)
                ->whereHas('users', function ($query) use ($user) {
                    $query->where('users.id', $user->id);
                })
                ->first();

            if ($company) {
                return $this->successResponse(new CompanyResource($company));
            }
        }

        $company = $user->companies()->first();

        if (!$company) {
            return $this->errorResponse('No companies available for user', 404);
        }

        return $this->successResponse(new CompanyResource($company));
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
        if (!$user) {
            return $this->errorResponse(null, 401);
        }

        $companyId = $request->company_id;

        if (!$companyId) {
            $company = $user->companies()->first();
            if (!$company) {
                return $this->errorResponse('No companies available', 404);
            }
            $companyId = $company->id;
        } else {
            $company = $user->companies()->where('companies.id', $companyId)->first();

            if (!$company) {
                return $this->errorResponse('Company not found or access denied', 404);
            }
        }

        CacheService::invalidateCurrenciesCache();

        return $this->successResponse(new CompanyResource($company), 'Company selected successfully');
    }

    /**
     * Получить список компаний пользователя
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getUserCompanies()
    {
        $user = $this->getAuthenticatedUser();
        if (!$user) {
            return $this->errorResponse(null, 401);
        }

        $companies = $user->companies()->select(
            'companies.id',
            'companies.name',
            'companies.logo',
            'companies.show_deleted_transactions',
            'companies.rounding_decimals',
            'companies.rounding_enabled',
            'companies.rounding_direction',
            'companies.rounding_custom_threshold',
            'companies.rounding_quantity_decimals',
            'companies.rounding_quantity_enabled',
            'companies.rounding_quantity_direction',
            'companies.rounding_quantity_custom_threshold',
            'companies.skip_project_order_balance',
            'companies.work_schedule',
            'companies.created_at',
            'companies.updated_at'
        )->get();

        return $this->successResponse(CompanyResource::collection($companies)->resolve());
    }
}
