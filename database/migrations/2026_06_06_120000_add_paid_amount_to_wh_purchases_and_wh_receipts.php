<?php

use App\Models\Order;
use App\Models\WhPurchase;
use App\Models\WhReceipt;
use App\Repositories\OrdersRepository;
use App\Services\WarehouseDocumentPaymentStatusService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('wh_purchases', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 5)->default(0)->after('amount');
        });

        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->decimal('paid_amount', 15, 5)->default(0)->after('amount');
        });

        $paymentService = app(WarehouseDocumentPaymentStatusService::class);

        WhPurchase::query()->orderBy('id')->pluck('id')->each(function ($id) use ($paymentService) {
            $paymentService->syncPurchasePaidAmount((int) $id);
        });

        WhReceipt::query()
            ->whereNull('purchase_id')
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use ($paymentService) {
                $paymentService->syncReceiptPaidAmount((int) $id);
            });

        $ordersRepository = app(OrdersRepository::class);
        Order::query()->orderBy('id')->pluck('id')->each(function ($id) use ($ordersRepository) {
            $ordersRepository->updateOrderPaidAmount((int) $id);
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::table('wh_receipts', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });

        Schema::table('wh_purchases', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });
    }
};
