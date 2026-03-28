<?php

namespace App\Http\Controllers\Api;

use App\Services\CacheService;
use Illuminate\Http\JsonResponse;

class CacheController extends BaseController
{
    /**
     * Clear backend cache storage.
     *
     * @return JsonResponse
     */
    public function clear(): JsonResponse
    {
        CacheService::flushAll();

        return $this->successResponse([
            'success' => true,
            'message' => 'Cache cleared',
            'backend_cleared' => true,
        ]);
    }
}

