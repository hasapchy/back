<?php

namespace App\Services;

use App\Models\ClientBalance;
use App\Models\Order;
use App\Models\ProjectContract;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use Illuminate\Contracts\Validation\Validator;

final class DocumentParentBalanceResolver
{
    public const WH_RECEIPT_GOODS_CATEGORY_ID = 6;

    private const SOURCE_MODELS = [
        'Order' => Order::class,
        'WhReceipt' => WhReceipt::class,
        'WhPurchase' => WhPurchase::class,
        'ProjectContract' => ProjectContract::class,
    ];

    /**
     * @return int|null ID записи client_balances на родительском документе
     */
    public function resolve(?int $orderId, ?string $sourceType, ?int $sourceId): ?int
    {
        if ($orderId !== null && $orderId > 0) {
            $balanceId = Order::query()->whereKey($orderId)->value('client_balance_id');

            return $balanceId ? (int) $balanceId : null;
        }

        if (! $sourceType || ! $sourceId) {
            return null;
        }

        foreach (self::SOURCE_MODELS as $fragment => $modelClass) {
            if (! str_contains($sourceType, $fragment)) {
                continue;
            }

            $balanceId = $modelClass::query()->whereKey($sourceId)->value('client_balance_id');

            return $balanceId ? (int) $balanceId : null;
        }

        return null;
    }

    /**
     * Ручная оплата / расход по документу (не автокредит).
     *
     * @return void
     */
    public function assertManualDocumentPayment(
        Validator $validator,
        ?int $orderId,
        ?string $sourceType,
        ?int $sourceId,
        mixed $clientBalanceId,
        bool $isDebt,
        ?int $categoryId = null,
    ): void {
        if (! $this->isDocumentLinked($orderId, $sourceType, $sourceId)) {
            return;
        }

        if ($isDebt && $this->forbidsManualDocumentDebt($orderId, $sourceType)) {
            $validator->errors()->add(
                'is_debt',
                __('Записи в кредит по документу создаются автоматически при сохранении документа, а не вручную.')
            );

            return;
        }

        $parentBalanceId = $this->resolve($orderId, $sourceType, $sourceId);

        if ($parentBalanceId !== null && $this->requiresParentBalanceExactMatch($sourceType, $categoryId)) {
            $this->assertClientBalanceMatchesParent($validator, $parentBalanceId, $clientBalanceId);

            return;
        }

        $payeeClientId = $this->resolvePayeeClientId($orderId, $sourceType, $sourceId);

        if ($payeeClientId === null) {
            return;
        }

        if ($this->isWhReceiptNonGoodsExpenseWithoutBalance($sourceType, $categoryId, $clientBalanceId)) {
            return;
        }

        $this->assertClientBalancePresentForPayee($validator, $clientBalanceId, $payeeClientId);
    }

    /**
     * Долг по заказу, закупке и контракту создаётся при сохранении документа.
     * По оприходованию ручные расходы (доставка, прочие) оформляют кредит сами — не блокируем.
     */
    private function forbidsManualDocumentDebt(?int $orderId, ?string $sourceType): bool
    {
        if ($orderId !== null && $orderId > 0) {
            return true;
        }

        if (! $sourceType) {
            return false;
        }

        if (str_contains($sourceType, 'WhReceipt')) {
            return false;
        }

        foreach (['Order', 'WhPurchase', 'ProjectContract'] as $fragment) {
            if (str_contains($sourceType, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isDocumentLinked(?int $orderId, ?string $sourceType, ?int $sourceId): bool
    {
        if ($orderId !== null && $orderId > 0) {
            return true;
        }

        if (! $sourceType || ! $sourceId) {
            return false;
        }

        foreach (array_keys(self::SOURCE_MODELS) as $fragment) {
            if (str_contains($sourceType, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return int|null ID клиента / поставщика документа
     */
    public function resolvePayeeClientId(?int $orderId, ?string $sourceType, ?int $sourceId): ?int
    {
        if ($orderId !== null && $orderId > 0) {
            $clientId = Order::query()->whereKey($orderId)->value('client_id');

            return $clientId ? (int) $clientId : null;
        }

        if (! $sourceType || ! $sourceId) {
            return null;
        }

        if (str_contains($sourceType, 'Order')) {
            $clientId = Order::query()->whereKey($sourceId)->value('client_id');

            return $clientId ? (int) $clientId : null;
        }

        if (str_contains($sourceType, 'ProjectContract')) {
            $clientId = ProjectContract::query()->whereKey($sourceId)->value('client_id');

            return $clientId ? (int) $clientId : null;
        }

        if (str_contains($sourceType, 'WhPurchase')) {
            $supplierId = WhPurchase::query()->whereKey($sourceId)->value('supplier_id');

            return $supplierId ? (int) $supplierId : null;
        }

        if (str_contains($sourceType, 'WhReceipt')) {
            $supplierId = WhReceipt::query()->whereKey($sourceId)->value('supplier_id');

            return $supplierId ? (int) $supplierId : null;
        }

        return null;
    }

    /**
     * Доставка и прочие расходы по оприходованию (не категория «товар») — баланс в транзакции не обязателен.
     */
    private function isWhReceiptNonGoodsExpenseWithoutBalance(
        ?string $sourceType,
        ?int $categoryId,
        mixed $clientBalanceId,
    ): bool {
        if (! $sourceType || ! str_contains($sourceType, 'WhReceipt')) {
            return false;
        }

        if ($categoryId !== null && (int) $categoryId === self::WH_RECEIPT_GOODS_CATEGORY_ID) {
            return false;
        }

        return $clientBalanceId === null || $clientBalanceId === '';
    }

    /**
     * Оплата за товар по оприходованию — баланс как на документе; логистика и прочие расходы — любой баланс поставщика.
     */
    private function requiresParentBalanceExactMatch(?string $sourceType, ?int $categoryId): bool
    {
        if ($sourceType && str_contains($sourceType, 'WhReceipt')) {
            return $categoryId !== null
                && (int) $categoryId === self::WH_RECEIPT_GOODS_CATEGORY_ID;
        }

        return true;
    }

    /**
     * @return void
     */
    private function assertClientBalanceMatchesParent(
        Validator $validator,
        int $parentBalanceId,
        mixed $clientBalanceId,
    ): void {
        if ($clientBalanceId === null || $clientBalanceId === '') {
            $validator->errors()->add(
                'client_balance_id',
                __('Укажите баланс клиента — он должен совпадать с балансом документа.')
            );

            return;
        }

        if ((int) $clientBalanceId !== $parentBalanceId) {
            $validator->errors()->add(
                'client_balance_id',
                __('Баланс оплаты должен совпадать с балансом, выбранным в документе-основании.')
            );
        }
    }

    /**
     * @return void
     */
    private function assertClientBalancePresentForPayee(
        Validator $validator,
        mixed $clientBalanceId,
        int $payeeClientId,
    ): void {
        if ($clientBalanceId === null || $clientBalanceId === '') {
            $validator->errors()->add(
                'client_balance_id',
                __('Укажите баланс клиента для оплаты по документу.')
            );

            return;
        }

        $balance = ClientBalance::query()->find((int) $clientBalanceId);

        if ($balance && (int) $balance->client_id !== $payeeClientId) {
            $validator->errors()->add(
                'client_balance_id',
                __('Выбранный баланс не принадлежит клиенту документа.')
            );
        }
    }
}
