<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $companyId = $request->get('company_id');

        $query = Setting::query();
        if ($companyId) {
            $query->where('company_id', $companyId);
        } else {
            $query->whereNull('company_id');
        }

        $settings = $query->pluck('setting_value', 'setting_name');

        $companyLogo = $settings['company_logo'] ?? '';
        if ($companyLogo && str_starts_with($companyLogo, '/storage/')) {
            $companyLogo = url($companyLogo);
        }

        return response()->json([
            'company_name' => $settings['company_name'] ?? '',
            'company_logo' => $companyLogo,
            'company_id' => $companyId
        ]);
    }

    public function update(Request $request)
    {
        try {
            $request->validate([
                'company_name' => 'required|string|max:255',
                'company_logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048',
                'company_id' => 'nullable|exists:companies,id'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        $companyId = $request->company_id;

        Setting::updateOrCreate(
            [
                'setting_name' => 'company_name',
                'company_id' => $companyId
            ],
            ['setting_value' => $request->company_name]
        );

        if ($request->hasFile('company_logo')) {
            $file = $request->file('company_logo');
            $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();

            $path = $file->storeAs('uploads/logos', $filename, 'public');

            Setting::updateOrCreate(
                [
                    'setting_name' => 'company_logo',
                    'company_id' => $companyId
                ],
                ['setting_value' => url('/storage/' . $path)]
            );
        } elseif ($request->has('company_logo') && !$request->hasFile('company_logo')) {
            // Если передан URL логотипа (не файл)
            Setting::updateOrCreate(
                [
                    'setting_name' => 'company_logo',
                    'company_id' => $companyId
                ],
                ['setting_value' => $request->company_logo]
            );
        }

        return response()->json(['message' => 'Настройки обновлены']);
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
            ->select('companies.id', 'companies.name')
            ->get();

        return response()->json($companies);
    }
}
