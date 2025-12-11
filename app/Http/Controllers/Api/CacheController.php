<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseController;
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

        return response()->json([
            'success' => true,
            'message' => 'Cache cleared',
            'backend_cleared' => true,
        ]);
    }
}

