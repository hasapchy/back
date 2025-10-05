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
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $request->only(['name']);

        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('uploads/logos', $filename, 'public');
            $data['logo'] = 'storage/' . $path;
        }

        $company = Company::create($data);

        return response()->json(['company' => $company]);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => "required|string|max:255|unique:companies,name,{$id}",
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $company = Company::findOrFail($id);
        $data = $request->only(['name']);

        if ($request->hasFile('logo')) {
            // Удаляем старый логотип если есть
            if ($company->logo && $company->logo !== 'logo.jpg') {
                $oldPath = str_replace('storage/', 'public/', $company->logo);
                Storage::delete($oldPath);
            }

            $file = $request->file('logo');
            $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('uploads/logos', $filename, 'public');
            $data['logo'] = 'storage/' . $path;
        }

        $company->update($data);

        return response()->json(['company' => $company]);
    }

    public function destroy($id)
    {
        $company = Company::findOrFail($id);
        $company->delete();

        return response()->json(['message' => 'Company deleted']);
    }
}
