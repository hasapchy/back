<?php

namespace App\Repositories;

use App\Models\WhReceipt;
use App\Models\WhReceiptProduct;
use App\Models\WhWaybill;
use App\Models\WhWaybillProduct;
use App\Services\InventoryLockService;
use App\Services\RoundingService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseWaybillRepository extends BaseRepository
{
    public function __construct(
        private readonly WarehouseReceiptRepository $receiptRepository
    ) {
    }

    /**
     * Строки оприходования с остатком количества для накладной (учёт других накладных; при редактировании — без текущей).
     *
     * @return array<int, array{product_id: int, max_quantity: float, default_price: float, product_name: string, product_image: string|null, unit_id: int|null, unit_name: string|null, unit_short_name: string|null}>
     */
    public function allowedWaybillProductLines(int $receiptId, int $userUuid, ?int $editingWaybillId): array
    {
        $receipt = $this->receiptRepository->getItemById($receiptId, $userUuid);
        if (! $this->receiptAllowsDiscretionaryWaybills($receipt)) {
            return [];
        }

        $receiptProducts = WhReceiptProduct::query()
            ->where('receipt_id', $receiptId)
            ->with(['product:id,name,image,unit_id', 'product.unit:id,name,short_name'])
            ->orderBy('id')
            ->get();

        $used = WhWaybillProduct::query()
            ->join('wh_waybills', 'wh_waybill_products.waybill_id', '=', 'wh_waybills.id')
            ->where('wh_waybills.receipt_id', $receiptId)
            ->when($editingWaybillId !== null && $editingWaybillId > 0, function ($q) use ($editingWaybillId) {
                $q->where('wh_waybills.id', '!=', $editingWaybillId);
            })
            ->selectRaw('wh_waybill_products.product_id, sum(wh_waybill_products.quantity) as qty')
            ->groupBy('wh_waybill_products.product_id')
            ->pluck('qty', 'product_id');

        $rounding = new RoundingService();
        $companyId = $this->getCurrentCompanyId();
        $out = [];
        foreach ($receiptProducts as $rp) {
            $pid = (int) $rp->product_id;
            $cap = $rounding->roundQuantityForCompany($companyId, (float) $rp->quantity);
            $sumOther = $rounding->roundQuantityForCompany($companyId, (float) ($used[$pid] ?? 0));
            $maxRemaining = $rounding->roundQuantityForCompany($companyId, $cap - $sumOther);
            if ($maxRemaining <= 0) {
                continue;
            }
            $product = $rp->product;
            $out[] = [
                'product_id' => $pid,
                'max_quantity' => $maxRemaining,
                'default_price' => (float) $rp->price,
                'product_name' => (string) ($product?->name ?? ''),
                'product_image' => $product?->image,
                'unit_id' => $product?->unit_id,
                'unit_name' => $product?->unit?->name,
                'unit_short_name' => $product?->unit?->short_name,
            ];
        }

        return $out;
    }

    /**
     * @return Collection<int, WhWaybill>
     */
    public function listForReceipt(int $receiptId, int $userUuid): Collection
    {
        $receipt = $this->receiptRepository->getItemById($receiptId, $userUuid);
        if (! $receipt) {
            return new Collection();
        }

        return WhWaybill::query()
            ->where('receipt_id', $receiptId)
            ->with(['lines.product:id,name,image,unit_id', 'lines.product.unit:id,name,short_name', 'creator:id,name'])
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createForReceipt(int $receiptId, int $userUuid, array $data): WhWaybill
    {
        $receipt = $this->requireReceiptForWaybills($receiptId, $userUuid);

        return DB::transaction(function () use ($receipt, $data) {
            $rounding = new RoundingService();
            $companyId = $this->getCurrentCompanyId();
            $lines = $this->normalizeLinesInput($rounding, $companyId, $data['lines'] ?? []);

            $this->assertLinesWithinReceiptLimits($receipt, $lines, null);

            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $receipt->warehouse_id);

            $waybill = new WhWaybill();
            $waybill->receipt_id = $receipt->id;
            $waybill->date = $data['date'] ?? now();
            $waybill->number = $data['number'] ?? null;
            $waybill->note = $data['note'] ?? null;
            $waybill->creator_id = (int) auth('api')->id();
            $waybill->save();

            foreach ($lines as $line) {
                $wp = new WhWaybillProduct();
                $wp->waybill_id = $waybill->id;
                $wp->product_id = $line['product_id'];
                $wp->quantity = (string) $line['quantity'];
                $wp->price = (string) $line['price'];
                $wp->save();

                $this->receiptRepository->applyWarehouseStockDelta((int) $receipt->warehouse_id, $line['product_id'], (float) $line['quantity']);
                $this->receiptRepository->applyProductPurchasePriceUpdate($line['product_id'], (float) $line['price']);
            }

            $this->receiptRepository->syncReceiptFulfillmentStatus($receipt->fresh(['products', 'waybills.lines']));
            $this->receiptRepository->invalidateWarehouseReceiptCaches($receipt->project_id);

            return $waybill->fresh(['lines']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateWaybill(int $receiptId, int $waybillId, int $userUuid, array $data): WhWaybill
    {
        $waybill = WhWaybill::query()->with(['lines', 'receipt'])->findOrFail($waybillId);
        if ((int) $waybill->receipt_id !== $receiptId) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }
        $receipt = $this->requireReceiptForWaybills((int) $waybill->receipt_id, $userUuid);

        return DB::transaction(function () use ($waybill, $receipt, $data) {
            $rounding = new RoundingService();
            $companyId = $this->getCurrentCompanyId();

            foreach ($waybill->lines as $oldLine) {
                $this->receiptRepository->applyWarehouseStockDelta((int) $receipt->warehouse_id, (int) $oldLine->product_id, -(float) $oldLine->quantity);
            }
            WhWaybillProduct::query()->where('waybill_id', $waybill->id)->delete();

            $lines = $this->normalizeLinesInput($rounding, $companyId, $data['lines'] ?? []);
            $this->assertLinesWithinReceiptLimits($receipt, $lines, $waybill->id);

            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $receipt->warehouse_id);

            $waybill->date = $data['date'] ?? $waybill->date;
            $waybill->number = $data['number'] ?? $waybill->number;
            $waybill->note = $data['note'] ?? $waybill->note;
            $waybill->save();

            foreach ($lines as $line) {
                $wp = new WhWaybillProduct();
                $wp->waybill_id = $waybill->id;
                $wp->product_id = $line['product_id'];
                $wp->quantity = (string) $line['quantity'];
                $wp->price = (string) $line['price'];
                $wp->save();

                $this->receiptRepository->applyWarehouseStockDelta((int) $receipt->warehouse_id, $line['product_id'], (float) $line['quantity']);
                $this->receiptRepository->applyProductPurchasePriceUpdate($line['product_id'], (float) $line['price']);
            }

            $this->receiptRepository->syncReceiptFulfillmentStatus($receipt->fresh(['products', 'waybills.lines']));
            $this->receiptRepository->invalidateWarehouseReceiptCaches($receipt->project_id);

            return $waybill->fresh(['lines']);
        });
    }

    public function deleteWaybill(int $receiptId, int $waybillId, int $userUuid): bool
    {
        $waybill = WhWaybill::query()->with(['lines', 'receipt'])->findOrFail($waybillId);
        if ((int) $waybill->receipt_id !== $receiptId) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }
        $receipt = $this->requireReceiptForWaybills((int) $waybill->receipt_id, $userUuid);

        return DB::transaction(function () use ($waybill, $receipt) {
            app(InventoryLockService::class)->checkWarehouseIsUnlocked((int) $receipt->warehouse_id);

            foreach ($waybill->lines as $oldLine) {
                $this->receiptRepository->applyWarehouseStockDelta((int) $receipt->warehouse_id, (int) $oldLine->product_id, -(float) $oldLine->quantity);
            }

            $projectId = $receipt->project_id;
            $waybill->delete();

            $this->receiptRepository->syncReceiptFulfillmentStatus($receipt->fresh(['products', 'waybills.lines']));
            $this->receiptRepository->invalidateWarehouseReceiptCaches($projectId);

            return true;
        });
    }

    private function requireReceiptForWaybills(int $receiptId, int $userUuid): WhReceipt
    {
        $receipt = $this->receiptRepository->getItemById($receiptId, $userUuid);
        if (! $receipt) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException();
        }
        if ($receipt->is_legacy) {
            throw new \RuntimeException('WAYBILLS_NOT_ALLOWED_FOR_LEGACY_RECEIPT');
        }
        if ($receipt->is_simple) {
            throw new \RuntimeException('WAYBILL_READONLY_FOR_SIMPLE_RECEIPT');
        }

        return $receipt;
    }

    /**
     * @return bool
     */
    private function receiptAllowsDiscretionaryWaybills(?WhReceipt $receipt): bool
    {
        return $receipt !== null && ! $receipt->is_legacy && ! $receipt->is_simple;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{product_id: int, quantity: float, price: float}>
     */
    private function normalizeLinesInput(RoundingService $rounding, int $companyId, array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $pid = (int) ($line['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $out[] = [
                'product_id' => $pid,
                'quantity' => $rounding->roundQuantityForCompany($companyId, (float) ($line['quantity'] ?? 0)),
                'price' => (float) ($line['price'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: float, price: float}>  $lines
     */
    private function assertLinesWithinReceiptLimits(WhReceipt $receipt, array $lines, ?int $ignoreWaybillId): void
    {
        $allowed = [];
        foreach (WhReceiptProduct::query()->where('receipt_id', $receipt->id)->get() as $rp) {
            $allowed[(int) $rp->product_id] = (float) $rp->quantity;
        }

        $aggregated = [];
        foreach ($lines as $line) {
            $pid = $line['product_id'];
            if (! isset($allowed[$pid])) {
                throw new \RuntimeException('WAYBILL_PRODUCT_NOT_IN_RECEIPT');
            }
            $aggregated[$pid] = ($aggregated[$pid] ?? 0) + $line['quantity'];
        }

        $existing = WhWaybillProduct::query()
            ->whereHas('waybill', function ($q) use ($receipt, $ignoreWaybillId) {
                $q->where('receipt_id', $receipt->id);
                if ($ignoreWaybillId) {
                    $q->where('id', '!=', $ignoreWaybillId);
                }
            })
            ->selectRaw('product_id, sum(quantity) as qty')
            ->groupBy('product_id')
            ->pluck('qty', 'product_id');

        $rounding = new RoundingService();
        $companyId = $this->getCurrentCompanyId();

        foreach ($aggregated as $pid => $qty) {
            $total = (float) ($existing[$pid] ?? 0) + $qty;
            $total = $rounding->roundQuantityForCompany($companyId, $total);
            $cap = $rounding->roundQuantityForCompany($companyId, $allowed[$pid]);
            if ($total > $cap + 1e-9) {
                throw new \RuntimeException('WAYBILL_QUANTITY_EXCEEDS_RECEIPT_LINE');
            }
        }
    }
}
