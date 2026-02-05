<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;
use App\Models\Tenant;
use Symfony\Component\HttpFoundation\Response;

/**
 * Инициализирует tenancy по заголовку X-Company-ID: находит компанию и её tenant, переключает БД на tenant.
 */
class InitializeTenancyByCompanyHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $companyId = $request->header('X-Company-ID');

        if (empty($companyId)) {
            return $next($request);
        }

        $company = Company::find($companyId);

        if (!$company || empty($company->tenant_id)) {
            return $next($request);
        }

        $tenant = Tenant::on('central')->find($company->tenant_id);

        if (!$tenant) {
            return $next($request);
        }

        tenancy()->initialize($tenant);

        return $next($request);
    }
}
