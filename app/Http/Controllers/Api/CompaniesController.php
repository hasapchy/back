<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class CompaniesController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $companies = Company::select(['id', 'name', 'logo', 'show_deleted_transactions', 'rounding_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold', 'rounding_quantity_decimals', 'rounding_quantity_enabled', 'rounding_quantity_direction', 'rounding_quantity_custom_threshold', 'skip_project_order_balance', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->paginate($perPage);

        return $this->paginatedResponse($companies);
    }

    public function store(Request $request)
    {
        $data = $request->all();

        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }
        if (isset($data['rounding_quantity_custom_threshold']) && $data['rounding_quantity_custom_threshold'] === '') {
            $data['rounding_quantity_custom_threshold'] = null;
        }

        $validator = Validator::make($data, [
            'name' => 'required|string|max:255|unique:companies,name',
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240',
            'show_deleted_transactions' => 'nullable|boolean',
            'rounding_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_enabled' => 'nullable|boolean',
            'rounding_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'rounding_quantity_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_quantity_enabled' => 'nullable|boolean',
            'rounding_quantity_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_quantity_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'skip_project_order_balance' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $data = $request->only(['name', 'show_deleted_transactions', 'rounding_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold', 'rounding_quantity_decimals', 'rounding_quantity_enabled', 'rounding_quantity_direction', 'rounding_quantity_custom_threshold', 'skip_project_order_balance']);

        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['skip_project_order_balance'])) {
            $data['skip_project_order_balance'] = filter_var($data['skip_project_order_balance'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_quantity_enabled'])) {
            $data['rounding_quantity_enabled'] = filter_var($data['rounding_quantity_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }        if (isset($data['rounding_quantity_custom_threshold']) && $data['rounding_quantity_custom_threshold'] === '') {
            $data['rounding_quantity_custom_threshold'] = null;
        }
        if (isset($data['rounding_enabled'])) {
            $roundingEnabled = $data['rounding_enabled'];
            if ($roundingEnabled === false || $roundingEnabled === 'false' || $roundingEnabled === '0' || $roundingEnabled === 0) {
                $data['rounding_direction'] = null;
                $data['rounding_custom_threshold'] = null;
            }
        }
        if (isset($data['rounding_quantity_enabled'])) {
            $roundingQuantityEnabled = $data['rounding_quantity_enabled'];
            if ($roundingQuantityEnabled === false || $roundingQuantityEnabled === 'false' || $roundingQuantityEnabled === '0' || $roundingQuantityEnabled === 0) {
                $data['rounding_quantity_direction'] = null;
                $data['rounding_quantity_custom_threshold'] = null;
            }
        }

        if ($request->hasFile('logo')) {
            $data['logo'] = $request->file('logo')->store('companies', 'public');
        }

        $company = Company::create($data);

        CacheService::invalidateCompaniesCache();

        return response()->json(['company' => $company]);
    }

    public function update(Request $request, $id)
    {
        $data = $request->all();

        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_quantity_enabled'])) {
            $data['rounding_quantity_enabled'] = filter_var($data['rounding_quantity_enabled'], FILTER_VALIDATE_BOOLEAN);
        }

        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }
        if (isset($data['rounding_quantity_custom_threshold']) && $data['rounding_quantity_custom_threshold'] === '') {
            $data['rounding_quantity_custom_threshold'] = null;
        }

        $validator = Validator::make($data, [
            'name' => "required|string|max:255|unique:companies,name,{$id}",
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240',
            'show_deleted_transactions' => 'nullable|boolean',
            'rounding_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_enabled' => 'nullable|boolean',
            'rounding_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'rounding_quantity_decimals' => 'nullable|integer|min:0|max:5',
            'rounding_quantity_enabled' => 'nullable|boolean',
            'rounding_quantity_direction' => 'nullable|in:standard,up,down,custom',
            'rounding_quantity_custom_threshold' => 'nullable|numeric|min:0|max:1',
            'skip_project_order_balance' => 'nullable|boolean'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $company = Company::findOrFail($id);
        $data = $request->only(['name', 'show_deleted_transactions', 'rounding_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold', 'rounding_quantity_decimals', 'rounding_quantity_enabled', 'rounding_quantity_direction', 'rounding_quantity_custom_threshold', 'skip_project_order_balance']);

        if (isset($data['show_deleted_transactions'])) {
            $data['show_deleted_transactions'] = filter_var($data['show_deleted_transactions'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_enabled'])) {
            $data['rounding_enabled'] = filter_var($data['rounding_enabled'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['skip_project_order_balance'])) {
            $data['skip_project_order_balance'] = filter_var($data['skip_project_order_balance'], FILTER_VALIDATE_BOOLEAN);
        }
        if (isset($data['rounding_quantity_enabled'])) {
            $data['rounding_quantity_enabled'] = filter_var($data['rounding_quantity_enabled'], FILTER_VALIDATE_BOOLEAN);
        }


        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }
        if (isset($data['rounding_quantity_custom_threshold']) && $data['rounding_quantity_custom_threshold'] === '') {
            $data['rounding_quantity_custom_threshold'] = null;
        }
        if (isset($data['rounding_enabled'])) {
            $roundingEnabled = $data['rounding_enabled'];
            if ($roundingEnabled === false || $roundingEnabled === 'false' || $roundingEnabled === '0' || $roundingEnabled === 0) {
                $data['rounding_direction'] = null;
                $data['rounding_custom_threshold'] = null;
            }
        }
        if (isset($data['rounding_quantity_enabled'])) {
            $roundingQuantityEnabled = $data['rounding_quantity_enabled'];
            if ($roundingQuantityEnabled === false || $roundingQuantityEnabled === 'false' || $roundingQuantityEnabled === '0' || $roundingQuantityEnabled === 0) {
                $data['rounding_quantity_direction'] = null;
                $data['rounding_quantity_custom_threshold'] = null;
            }
        }

        if ($request->hasFile('logo')) {
            if ($company->logo && $company->logo !== 'logo.jpg') {
                Storage::disk('public')->delete($company->logo);
            }
            $data['logo'] = $request->file('logo')->store('companies', 'public');
        }

        try {
            $company->update($data);
        } catch (\Exception $e) {
            Log::error('Ошибка обновления компании: ' . $e->getMessage());
            Log::error('Данные: ' . json_encode($data));
            throw $e;
        }

        CacheService::invalidateCompaniesCache();

        $company = $company->fresh();

        return response()->json(['company' => $company]);
    }

    public function destroy($id)
    {
        $company = Company::findOrFail($id);
        $company->delete();

        CacheService::invalidateCompaniesCache();

        return response()->json(['message' => 'Company deleted']);
    }
}
