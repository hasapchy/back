<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CurrencyResource;
use App\Models\Currency;
use App\Support\CompanyScopedPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrenciesController extends BaseController
{
    /**
     * Список валют компании и глобальных с пагинацией (только просмотр).
     *
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        $user = $this->getAuthenticatedUser();

        if (! $user) {
            return $this->errorResponse(null, 401);
        }

        if (! CompanyScopedPermissions::userHasAny($user, [
            'currency_history_view',
            'currency_history_view_all',
            'currency_history_view_own',
            'settings_currencies_view',
        ])) {
            return $this->errorResponse('Forbidden', 403);
        }

        $companyId = $this->getCurrentCompanyId();
        $hasAccessToNonDefaultCurrencies = CompanyScopedPermissions::userHas($user, 'settings_currencies_view');

        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 20);

        $query = Currency::query()->where('status', 1);

        if ($companyId) {
            $query->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            });
        } else {
            $query->whereNull('company_id');
        }

        if (! $hasAccessToNonDefaultCurrencies) {
            $query->where('is_default', true);
        }

        $query->orderByDesc('is_default')->orderBy('code');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return $this->successResponse([
            'items' => CurrencyResource::collection($paginator->items())->resolve(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'next_page' => $paginator->nextPageUrl(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
