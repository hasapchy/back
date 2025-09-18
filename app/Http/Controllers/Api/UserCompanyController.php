<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserCompanyController extends Controller
{
    public function getCurrentCompany(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Логируем для отладки
        Log::info("UserCompanyController::getCurrentCompany - User: {$user->id}");

        // Получаем выбранную компанию из заголовка X-Company-ID или параметра
        $selectedCompanyId = $request->header('X-Company-ID') ?? $request->input('company_id');

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

        // Если нет выбранной компании или она недоступна, возвращаем первую доступную
        $company = $user->companies()->first();

        if (!$company) {
            return response()->json(['error' => 'No companies available for user'], 404);
        }

        return response()->json(['company' => $company]);
    }

    public function setCurrentCompany(Request $request)
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $companyId = $request->company_id;

        // Если company_id не передан, берем первую доступную компанию
        if (!$companyId) {
            $company = $user->companies()->first();
            if (!$company) {
                return response()->json(['error' => 'No companies available'], 404);
            }
            $companyId = $company->id;
        } else {
            // Проверяем, что пользователь имеет доступ к этой компании
            $company = $user->companies()->where('companies.id', $companyId)->first();

            if (!$company) {
                return response()->json(['error' => 'Company not found or access denied'], 404);
            }
        }

        // Логируем для отладки
        Log::info("UserCompanyController::setCurrentCompany - Setting company ID: {$companyId} for user: {$user->id}");

        return response()->json(['message' => 'Company selected successfully', 'company' => $company]);
    }

    public function getUserCompanies()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Получаем компании пользователя через прямой запрос
        $companies = DB::table('companies')
            ->join('company_user', 'companies.id', '=', 'company_user.company_id')
            ->where('company_user.user_id', $user->id)
            ->select('companies.id', 'companies.name', 'companies.logo')
            ->get();

        return response()->json($companies);
    }
}
