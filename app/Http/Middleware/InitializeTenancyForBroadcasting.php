<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Инициализирует tenancy для запросов broadcasting/auth по имени канала (company.{companyId}.chat.* или company.{companyId}.presence).
 * Таблицы chat_participants и chats лежат в tenant-БД, поэтому без tenancy авторизация канала падает с "table not found".
 */
class InitializeTenancyForBroadcasting
{
    public function handle(Request $request, Closure $next): Response
    {
        $channelName = $request->input('channel_name', '');
        $channelName = preg_replace('#^(private-|presence-)#', '', $channelName);

        if (!preg_match('#^company\.(\d+)#', $channelName, $m)) {
            return $next($request);
        }

        $companyId = (int) $m[1];
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
