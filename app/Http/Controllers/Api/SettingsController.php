<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = Setting::pluck('setting_value', 'setting_name');

        $companyLogo = $settings['company_logo'] ?? '';
        if ($companyLogo && str_starts_with($companyLogo, '/storage/')) {
            $companyLogo = url($companyLogo);
        }

        return response()->json([
            'company_name' => $settings['company_name'] ?? '',
            'company_logo' => $companyLogo
        ]);
    }

    public function update(Request $request)
    {
        try {
            $request->validate([
                'company_name' => 'required|string|max:255',
                'company_logo' => 'nullable|image|mimes:jpeg,png,jpg,svg|max:2048'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }

        Setting::updateOrCreate(
            ['setting_name' => 'company_name'],
            ['setting_value' => $request->company_name]
        );

        if ($request->hasFile('company_logo')) {
            $file = $request->file('company_logo');
            $filename = 'logo_' . time() . '.' . $file->getClientOriginalExtension();

            $path = $file->storeAs('uploads/logos', $filename, 'public');

            Setting::updateOrCreate(
                ['setting_name' => 'company_logo'],
                ['setting_value' => url('/storage/' . $path)]
            );
        }

        return response()->json(['message' => 'Настройки обновлены']);
    }
}
