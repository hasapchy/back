<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Отдаёт файлы из tenant storage (чаты, проекты, задачи и т.д.).
 * companyId в URL, т.к. <img src> не отправляет заголовки.
 */
class TenantStorageController
{
    /**
     * Проверка доступа и стрим файла из storage текущего tenant.
     */
    public function show(Request $request, string $companyId, string $path): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $company = Company::find($companyId);
        if (!$company || empty($company->tenant_id)) {
            return response()->json(['message' => 'Company not found or has no tenant'], 404);
        }

        $hasAccess = DB::connection('central')
            ->table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->exists();
        if (!$hasAccess) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $tenant = Tenant::on('central')->find($company->tenant_id);
        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        tenancy()->initialize($tenant);
        try {
            $path = ltrim($path, '/');
            // Защита от Directory Traversal: путь не должен содержать ..
            if (str_contains($path, '..')) {
                return response()->json(['message' => 'Invalid path'], 400);
            }
            if (!Storage::disk('public')->exists($path)) {
                return response()->json(['message' => 'File not found'], 404);
            }
            $mime = Storage::disk('public')->mimeType($path) ?: 'application/octet-stream';
            $stream = Storage::disk('public')->readStream($path);

            return response()->stream(function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline',
            ]);
        } finally {
            tenancy()->end();
        }
    }
}
