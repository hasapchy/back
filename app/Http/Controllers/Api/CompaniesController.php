<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class CompaniesController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $companies = Company::select(['id', 'name', 'logo', 'show_deleted_transactions', 'rounding_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->paginate($perPage);

        return response()->json([
            'data' => $companies->items(),
            'current_page' => $companies->currentPage(),
            'last_page' => $companies->lastPage(),
            'per_page' => $companies->perPage(),
            'total' => $companies->total(),
            'from' => $companies->firstItem(),
            'to' => $companies->lastItem(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        // Обрабатываем boolean поля
        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        // Преобразуем пустую строку в null для rounding_custom_threshold
        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }

        $validator = Validator::make($data, [
            'name' => 'required|string|max:255|unique:companies,name',
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240',
            'show_deleted_transactions' => 'nullable|boolean',
            'rounding_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_enabled' => 'nullable|boolean',
            'rounding_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_custom_threshold' => 'nullable|numeric|min:0|max:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name', 'show_deleted_transactions', 'rounding_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold']);

        // Повторно обрабатываем boolean после only()
        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        // Преобразуем пустую строку в null для rounding_custom_threshold после only()
        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('companies', 'public');
        }

        $company = Company::create($data);

        // Инвалидируем кэш компаний
        CacheService::invalidateCompaniesCache();

        return response()->json(['company' => $company]);
    }

    public function update(Request $request, $id)
    {
        $data = $request->all();

        // Обрабатываем boolean поля
        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        // Преобразуем пустую строку в null для rounding_custom_threshold
        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }

        $validator = Validator::make($data, [
            'name' => "required|string|max:255|unique:companies,name,{$id}",
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240',
            'show_deleted_transactions' => 'nullable|boolean',
            'rounding_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_enabled' => 'nullable|boolean',
            'rounding_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_custom_threshold' => 'nullable|numeric|min:0|max:1'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $company = Company::findOrFail($id);
        $data = $request->only(['name', 'show_deleted_transactions', 'rounding_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold']);

        // Повторно обрабатываем boolean после only()
        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        // Преобразуем пустую строку в null для rounding_custom_threshold после only()
        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }

        if ($request->hasFile('logo')) {
            // Удаляем старый логотип если есть
            if ($company->logo && $company->logo !== 'logo.jpg') {
                Storage::disk('public')->delete($company->logo);
            }
            $data['logo'] = $request->file('logo')->store('companies', 'public');
        }

        $company->update($data);

        // Инвалидируем кэш компаний
        CacheService::invalidateCompaniesCache();

        // Получаем свежие данные из базы
        $company = $company->fresh();

        return response()->json(['company' => $company]);
    }

    public function destroy($id)
    {
        $company = Company::findOrFail($id);
        $company->delete();

        // Инвалидируем кэш компаний
        CacheService::invalidateCompaniesCache();

        return response()->json(['message' => 'Company deleted']);
    }
}
