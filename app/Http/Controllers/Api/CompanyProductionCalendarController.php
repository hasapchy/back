<?php

namespace App\Http\Controllers\Api;

use App\Models\CompanyProductionCalendarDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyProductionCalendarController extends BaseController
{
    public function all(Request $request): JsonResponse
    {
        return $this->withCurrentCompany(function (int $companyId) use ($request) {
            $request->validate([
                'year' => 'nullable|integer|min:2000|max:2100',
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d',
            ]);

            $query = CompanyProductionCalendarDay::query()->where('company_id', $companyId);

            if ($request->filled('year')) {
                $query->whereYear('date', (int) $request->input('year'));
            }
            if ($request->filled('date_from')) {
                $query->where('date', '>=', $request->input('date_from'));
            }
            if ($request->filled('date_to')) {
                $query->where('date', '<=', $request->input('date_to'));
            }

            $items = $query->orderBy('date')
                ->get(['id', 'date'])
                ->map(fn (CompanyProductionCalendarDay $row) => [
                    'id' => (int) $row->id,
                    'date' => $row->date->format('Y-m-d'),
                ])
                ->values()
                ->all();

            return $this->successResponse($items);
        });
    }

    public function store(Request $request): JsonResponse
    {
        return $this->withCurrentCompany(function (int $companyId) use ($request) {
            $validated = $request->validate([
                'dates' => 'required|array|min:1|max:'.self::BATCH_IDS_MAX,
                'dates.*' => 'date_format:Y-m-d',
            ]);

            $created = 0;
            foreach (array_unique($validated['dates']) as $dateStr) {
                $row = CompanyProductionCalendarDay::query()->firstOrCreate([
                    'company_id' => $companyId,
                    'date' => $dateStr,
                ]);
                if ($row->wasRecentlyCreated) {
                    $created++;
                }
            }

            return $this->successResponse([
                'created' => $created,
                'total_requested' => count($validated['dates']),
            ]);
        });
    }

    public function destroy(int $id): JsonResponse
    {
        return $this->withCurrentCompany(function (int $companyId) use ($id) {
            $row = CompanyProductionCalendarDay::query()
                ->where('company_id', $companyId)
                ->whereKey($id)
                ->first();
            if (! $row) {
                return $this->errorResponse('Запись не найдена', 404);
            }
            $row->delete();

            return $this->successResponse(null);
        });
    }

    /**
     * @param  callable(int): JsonResponse  $fn
     */
    private function withCurrentCompany(callable $fn): JsonResponse
    {
        $this->getAuthenticatedUserIdOrFail();
        $companyId = $this->getCurrentCompanyId();
        if (! $companyId) {
            return $this->errorResponse('Не указана компания', 422);
        }

        return $fn($companyId);
    }
}
