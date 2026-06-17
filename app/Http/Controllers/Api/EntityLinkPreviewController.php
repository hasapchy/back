<?php

namespace App\Http\Controllers\Api;

use App\Services\Chat\EntityLinkShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntityLinkPreviewController extends BaseController
{
    /**
     * @param EntityLinkShareService $entityLinkShareService
     */
    public function __construct(
        private readonly EntityLinkShareService $entityLinkShareService,
    ) {
    }

    /**
     * Preview entity link card data for chat rendering.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function preview(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $entity = (string) $request->query('entity', '');
        $entityId = (int) $request->query('entity_id', 0);

        if ($entity === '' || $entityId <= 0) {
            return $this->errorResponse(__('api.entity_link.invalid'), 422);
        }

        $data = $this->entityLinkShareService->preview($user, $entity, $entityId);
        if ($data === null) {
            return $this->errorResponse(__('api.common.not_found'), 404);
        }

        return $this->successResponse($data);
    }
}
