<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class UserCompanyController extends Controller
{
    public function getCurrentCompany()
    {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Получаем выбранную компанию из сессии или первую доступную
        $selectedCompanyId = session('selected_company_id');

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

        // Если нет выбранной компании, возвращаем первую доступную
        $company = $user->companies()->first();

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

        // Сохраняем выбранную компанию в сессии
        session(['selected_company_id' => $companyId]);

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
