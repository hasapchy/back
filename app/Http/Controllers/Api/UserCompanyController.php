<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
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
            return $this->unauthorizedResponse();
        }

        $selectedCompanyId = $this->getCurrentCompanyId() ?? $request->input('company_id');

        if ($selectedCompanyId) {
            $company = Company::where('id', $selectedCompanyId)
                ->whereHas('users', function($query) use ($user) {
                    $query->where('user_id', $user->id);
                })
                ->first();

            if ($company) {
                return response()->json(['company' => $company]);
            }
        }

        $company = $user->companies()->first();

        if (!$company) {
            return $this->notFoundResponse('No companies available for user');
        }

        return response()->json(['company' => $company]);
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
            return $this->unauthorizedResponse();
        }

        $companyId = $request->company_id;

        if (!$companyId) {
            $company = $user->companies()->first();
            if (!$company) {
                return $this->notFoundResponse('No companies available');
            }
            $companyId = $company->id;
        } else {
            $company = $user->companies()->where('companies.id', $companyId)->first();

            if (!$company) {
                return $this->notFoundResponse('Company not found or access denied');
            }
        }

        CacheService::invalidateCurrenciesCache();

        return response()->json(['company' => $company, 'message' => 'Company selected successfully']);
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
            return $this->unauthorizedResponse();
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

        return response()->json($companies);
    }
}
