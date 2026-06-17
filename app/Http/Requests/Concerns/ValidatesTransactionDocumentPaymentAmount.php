<?php

namespace App\Http\Requests\Concerns;

use App\Models\Order;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Services\OrderPaymentLimitService;
use App\Support\ResolvedCompany;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesTransactionDocumentPaymentAmount
{
    /**
     * @return void
     */
    protected function assertContractPaymentWithinRemaining(
        Validator $validator,
        ?string $sourceType,
        ?int $sourceId,
        ?int $projectId,
        float $origAmount,
        bool $isDebt,
        ?int $excludeTransactionId = null,
    ): void {
        if ($isDebt || ! $sourceType || ! $sourceId || ! str_contains($sourceType, 'ProjectContract')) {
            return;
        }

        $contract = ProjectContract::query()->find($sourceId);
        if (! $contract) {
            $validator->errors()->add('source_id', __('Контракт не найден.'));

            return;
        }

        if ($projectId && (int) $contract->project_id !== (int) $projectId) {
            $validator->errors()->add('source_id', __('Контракт не принадлежит выбранному проекту.'));
        }

        $remaining = max(0, (float) $contract->amount - (float) $contract->paid_amount);
        if ($excludeTransactionId !== null && $excludeTransactionId > 0) {
            $transaction = Transaction::query()->find($excludeTransactionId);
            if ($transaction && ! $transaction->is_debt && ! $transaction->is_deleted) {
                $remaining += (float) $transaction->orig_amount;
            }
        }

        if ($origAmount > $remaining + 0.01) {
            $validator->errors()->add('orig_amount', __('project_contract.payment_exceeds_remaining'));
        }
    }

    /**
     * @return void
     */
    protected function assertOrderPaymentWithinRemaining(
        Validator $validator,
        ?int $orderId,
        ?string $sourceType,
        ?int $sourceId,
        float $origAmount,
        int $currencyId,
        bool $isDebt,
        ?int $transactionType,
        ?int $excludeTransactionId = null,
        ?string $date = null,
    ): void {
        if ($isDebt || (int) $transactionType !== 1) {
            return;
        }

        $service = app(OrderPaymentLimitService::class);
        $resolvedOrderId = $service->resolveOrderId($orderId, $sourceType, $sourceId);
        if ($resolvedOrderId === null) {
            return;
        }

        $order = Order::query()->find($resolvedOrderId);
        if (! $order) {
            $validator->errors()->add('source_id', __('Заказ не найден.'));

            return;
        }

        if ($service->exceedsRemaining(
            $order,
            $origAmount,
            $currencyId,
            ResolvedCompany::fromRequest(),
            $date,
            $excludeTransactionId,
        )) {
            $validator->errors()->add('orig_amount', __('order.payment_exceeds_remaining'));
        }
    }
}
