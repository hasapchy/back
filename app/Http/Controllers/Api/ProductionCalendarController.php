<?php

namespace App\Http\Controllers\Api;

use App\Models\ProductionCalendarDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Кадры
 * @subgroup Производственный календарь
 */
class ProductionCalendarController extends BaseController
{
    /**
     * Список производственного календаря
     */
    public function all(Request $request): JsonResponse
    {
        return $this->withCurrentCompany(function (int $companyId) use ($request) {
            $request->validate([
                'year' => 'nullable|integer|min:2000|max:2100',
                'date_from' => 'nullable|date_format:Y-m-d',
                'date_to' => 'nullable|date_format:Y-m-d',
            ]);

            $query = ProductionCalendarDay::query()->where('company_id', $companyId);

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
                ->map(fn (ProductionCalendarDay $row) => [
                    'id' => (int) $row->id,
                    'date' => $row->date->format('Y-m-d'),
                ])
                ->values()
                ->all();

            return $this->successResponse($items);
        });
    }

    /**
     * Добавить дни в производственный календарь
     */
    public function store(Request $request): JsonResponse
    {
        return $this->withCurrentCompany(function (int $companyId) use ($request) {
            $validated = $request->validate([
                'dates' => 'required|array|min:1|max:'.self::BATCH_IDS_MAX,
                'dates.*' => 'date_format:Y-m-d',
            ]);

            $created = 0;
            foreach (array_unique($validated['dates']) as $dateStr) {
                $row = ProductionCalendarDay::query()->firstOrCreate([
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

    /**
     * Обновить день в производственном календаре
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->withCurrentCompany(function (int $companyId) use ($request, $id) {
            $validated = $request->validate([
                'date' => 'required|date_format:Y-m-d',
            ]);

            $row = ProductionCalendarDay::query()
                ->where('company_id', $companyId)
                ->whereKey($id)
                ->first();

            if (! $row) {
                return $this->errorResponse(__('Запись не найдена'), 404);
            }

            $targetDate = $validated['date'];
            $exists = ProductionCalendarDay::query()
                ->where('company_id', $companyId)
                ->where('date', $targetDate)
                ->whereKeyNot($id)
                ->exists();

            if ($exists) {
                return $this->errorResponse(__('Дата уже существует'), 422);
            }

            $row->date = $targetDate;
            $row->save();

            return $this->successResponse([
                'id' => (int) $row->id,
                'date' => $row->date->format('Y-m-d'),
            ]);
        });
    }

    /**
     * Удалить день из производственного календаря
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->withCurrentCompany(function (int $companyId) use ($id) {
            $row = ProductionCalendarDay::query()
                ->where('company_id', $companyId)
                ->whereKey($id)
                ->first();
            if (! $row) {
                return $this->errorResponse(__('Запись не найдена'), 404);
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
            return $this->errorResponse(__('Не указана компания'), 422);
        }

        return $fn($companyId);
    }
}
