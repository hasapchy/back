<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
use App\Models\Company;
use App\Repositories\RolesRepository;
use App\Services\CacheService;
use App\Http\Requests\StoreCompanyRequest;
use App\Http\Requests\UpdateCompanyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $companies = Company::select(['id', 'name', 'logo', 'show_deleted_transactions', 'rounding_decimals', 'rounding_enabled', 'rounding_direction', 'rounding_custom_threshold', 'rounding_quantity_decimals', 'rounding_quantity_enabled', 'rounding_quantity_direction', 'rounding_quantity_custom_threshold', 'skip_project_order_balance', 'created_at', 'updated_at'])
            ->orderBy('name')
            ->paginate($perPage);

        return $this->paginatedResponse($companies);
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

        return response()->json(['company' => $company]);
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

        return response()->json(['company' => $company]);
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

        return response()->json(['message' => 'Company deleted']);
    }
}
