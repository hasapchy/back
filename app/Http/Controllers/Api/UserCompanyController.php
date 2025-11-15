<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Контроллер для работы с компаниями пользователя
 */
class UserCompanyController extends Controller
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

        $companies = $user->companies()->select('companies.id', 'companies.name', 'companies.logo')->get();

        return response()->json($companies);
    }
}
