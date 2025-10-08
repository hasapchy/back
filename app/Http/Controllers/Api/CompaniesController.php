<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class CompaniesController extends Controller
{
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 10);

        $companies = Company::select(['id', 'name', 'logo', 'created_at', 'updated_at'])
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
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:companies,name',
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name']);

        if ($request->hasFile('logo')) {
            $logoPath = $request->file('logo')->store('companies', 'public');
            $data['logo'] = $logoPath;
            \Log::info('Company logo stored', ['path' => $logoPath]);
        }

        $company = Company::create($data);
        
        \Log::info('Company created', ['id' => $company->id, 'logo' => $company->logo]);

        return response()->json(['company' => $company]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => "required|string|max:255|unique:companies,name,{$id}",
            'logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,webp,svg|max:10240'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $company = Company::findOrFail($id);
        $data = $request->only(['name']);

        if ($request->hasFile('logo')) {
            // Удаляем старый логотип если есть
            if ($company->logo && $company->logo !== 'logo.jpg') {
                Storage::disk('public')->delete($company->logo);
            }
            $logoPath = $request->file('logo')->store('companies', 'public');
            $data['logo'] = $logoPath;
            \Log::info('Company logo updated', ['id' => $id, 'path' => $logoPath]);
        }

        $company->update($data);
        
        // Получаем свежие данные из базы
        $company = $company->fresh();
        
        \Log::info('Company updated', ['id' => $company->id, 'logo' => $company->logo]);

        return response()->json(['company' => $company]);
    }

    public function destroy($id)
    {
        $company = Company::findOrFail($id);
        $company->delete();

        return response()->json(['message' => 'Company deleted']);
    }
}
