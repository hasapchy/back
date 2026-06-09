<?php

namespace App\Http\Controllers\Api;

use App\Enums\ListFilterPresetSource;
use App\Http\Requests\SetDefaultUserFilterPresetRequest;
use App\Http\Requests\StoreUserFilterPresetRequest;
use App\Http\Requests\UpdateUserFilterPresetRequest;
use App\Services\UserFilterPresetsService;
use App\Support\ListFilterPresetFields;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * @group Пользователи
 * @subgroup Пресеты фильтров
 */
class UserFilterPresetsController extends BaseController
{
    public function __construct(
        private readonly UserFilterPresetsService $presetsService,
    ) {
    }

    /**
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse(__('api.common.company_context_required'), 422);
        }

        $validated = $request->validate([
            'source' => ['required', 'string', 'in:'.implode(',', ListFilterPresetSource::values())],
        ]);

        $source = ListFilterPresetSource::from($validated['source']);

        return $this->successResponse($this->presetsService->listForUser($user, $companyId, $source));
    }

    /**
     * @return JsonResponse
     */
    public function store(StoreUserFilterPresetRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse(__('api.common.company_context_required'), 422);
        }

        $validated = $request->validated();
        $source = ListFilterPresetSource::from($validated['source']);

        try {
            ListFilterPresetFields::assertOnlyAllowedKeys($source, $validated['filters']);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        }

        if ($this->presetsService->nameExists($user, $companyId, $source, $validated['name'])) {
            return $this->errorResponse(__('api.filter_presets.name_exists'), 422);
        }

        try {
            $preset = $this->presetsService->create(
                $user,
                $companyId,
                $source,
                $validated['name'],
                $validated['filters'],
                $validated['icon'],
                $validated['color'],
            );
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (QueryException) {
            return $this->errorResponse(__('api.filter_presets.name_exists'), 422);
        }

        return $this->successResponse([
            'id' => $preset->id,
            'source' => $preset->source,
            'name' => $preset->name,
            'icon' => $preset->icon,
            'color' => $preset->color,
            'filters' => $preset->filters,
        ], null, 201);
    }

    /**
     * @return JsonResponse
     */
    public function update(UpdateUserFilterPresetRequest $request, int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse(__('api.common.company_context_required'), 422);
        }

        $validated = $request->validated();

        try {
            $preset = $this->presetsService->findOwnedOrFail($user, $companyId, $id);
        } catch (InvalidArgumentException) {
            return $this->errorResponse(__('api.filter_presets.not_found'), 404);
        }

        $source = ListFilterPresetSource::from($preset->source);

        if (array_key_exists('name', $validated)
            && $this->presetsService->nameExists($user, $companyId, $source, $validated['name'], $id)) {
            return $this->errorResponse(__('api.filter_presets.name_exists'), 422);
        }

        if (array_key_exists('filters', $validated)) {
            try {
                ListFilterPresetFields::assertOnlyAllowedKeys($source, $validated['filters']);
            } catch (InvalidArgumentException $e) {
                return $this->errorResponse($e->getMessage(), 422);
            }
        }

        try {
            $preset = $this->presetsService->updatePreset($user, $companyId, $id, $validated);
        } catch (InvalidArgumentException $e) {
            return $this->errorResponse($e->getMessage(), 422);
        } catch (QueryException) {
            return $this->errorResponse(__('api.filter_presets.name_exists'), 422);
        }

        return $this->successResponse([
            'id' => $preset->id,
            'source' => $preset->source,
            'name' => $preset->name,
            'icon' => $preset->icon,
            'color' => $preset->color,
            'filters' => $preset->filters,
        ]);
    }

    /**
     * @return JsonResponse
     */
    public function setDefault(SetDefaultUserFilterPresetRequest $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse(__('api.common.company_context_required'), 422);
        }

        $validated = $request->validated();
        $source = ListFilterPresetSource::from($validated['source']);
        $presetId = array_key_exists('preset_id', $validated) ? $validated['preset_id'] : null;

        try {
            $this->presetsService->setDefault($user, $companyId, $source, $presetId);
        } catch (InvalidArgumentException $e) {
            if ($presetId !== null) {
                return $this->errorResponse(__('api.filter_presets.not_found'), 404);
            }

            return $this->errorResponse($e->getMessage(), 422);
        }

        return $this->successResponse([
            'defaultPresetId' => $this->presetsService->resolveDefaultPresetId($user, $companyId, $source),
        ]);
    }

    /**
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = (int) $this->getCurrentCompanyId();
        if ($companyId < 1) {
            return $this->errorResponse(__('api.common.company_context_required'), 422);
        }

        try {
            $this->presetsService->delete($user, $companyId, $id);
        } catch (InvalidArgumentException) {
            return $this->errorResponse(__('api.filter_presets.not_found'), 404);
        }

        return $this->successResponse(null);
    }
}
