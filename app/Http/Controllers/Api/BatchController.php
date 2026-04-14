<?php

namespace App\Http\Controllers\Api;

use App\Batch\BatchService;
use App\Batch\Exceptions\UnknownBatchOperationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class BatchController extends BaseController
{
    public function execute(Request $request, BatchService $batchService): JsonResponse
    {
        $user = $this->requireAuthenticatedUser();
        $companyId = $this->getCurrentCompanyId();

        try {
            $result = $batchService->execute(
                $request->all(),
                $user,
                false,
                $companyId,
                $request->header('Idempotency-Key'),
            );
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (UnknownBatchOperationException $e) {
            return response()->json($this->batchErrorEnvelope($e->getMessage()), 404);
        } catch (AccessDeniedHttpException $e) {
            return response()->json($this->batchErrorEnvelope($e->getMessage()), 403);
        }

        foreach ($result->errors as $err) {
            if (($err['code'] ?? '') === 'orders_needs_payment') {
                return response()->json([
                    'error' => $err['message'] ?? 'Требуется оплата',
                    'needs_payment' => true,
                    'order_id' => $err['order_id'] ?? null,
                    'remaining_amount' => $err['remaining_amount'] ?? null,
                    'paid_total' => $err['paid_total'] ?? null,
                    'order_total' => $err['order_total'] ?? null,
                ], 422);
            }
        }

        $status = $result->correlationId !== null && $result->successCount === 0 ? 202 : 200;

        return $this->successResponse($result->toArray(), null, $status);
    }

    private function batchErrorEnvelope(string $message): array
    {
        return [
            'success_count' => 0,
            'failed_ids' => [],
            'errors' => [['message' => $message]],
            'async_job_id' => null,
            'async_connection' => null,
            'correlation_id' => null,
            'strategy_used' => null,
        ];
    }
}
