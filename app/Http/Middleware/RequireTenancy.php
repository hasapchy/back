<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Требует инициализированный tenant для маршрутов, работающих только с tenant-БД.
 * Проверяет наличие X-Company-ID и что у компании есть tenant_id (tenancy инициализируется в InitializeTenancyByCompanyHeader).
 */
class RequireTenancy
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-ID');

        if (empty($companyId)) {
            return response()->json([
                'message' => 'Заголовок X-Company-ID обязателен для этого запроса.',
            ], 422);
        }

        $company = Company::find($companyId);

        if (!$company) {
            return response()->json([
                'message' => 'Компания не найдена.',
            ], 422);
        }

        if (empty($company->tenant_id)) {
            return response()->json([
                'message' => 'У компании не настроена тенантная БД (tenant).',
            ], 422);
        }

        return $next($request);
    }
}
