<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Transaction;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Concerns\BuildsDocumentPaymentApiContext;
use Tests\TestCase;

class TransactionActivityLogTest extends TestCase
{
    use BuildsDocumentPaymentApiContext;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDocumentPaymentApiContext();
    }

    public function test_manual_payment_creates_single_activity_log(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);

        $before = Activity::query()->where('log_name', 'transaction')->count();

        $transaction = $this->storeManualOrderPayment($order);

        $logs = Activity::query()
            ->where('log_name', 'transaction')
            ->where('subject_type', Transaction::class)
            ->where('subject_id', $transaction->id)
            ->get();

        $this->assertSame(1, $logs->count());
        $this->assertSame('created', $logs->first()->event);
        $this->assertSame(1, Activity::query()->where('log_name', 'transaction')->count() - $before);
    }

    public function test_debt_transaction_with_source_does_not_create_activity_log(): void
    {
        $order = $this->createOrder(['client_balance_id' => $this->clientBalance->id]);
        $before = Activity::query()->where('log_name', 'transaction')->count();

        $transaction = $this->createAutoDebtOrderTransaction($order);

        $this->assertSame(0, Activity::query()
            ->where('log_name', 'transaction')
            ->where('subject_type', Transaction::class)
            ->where('subject_id', $transaction->id)
            ->count());
        $this->assertSame($before, Activity::query()->where('log_name', 'transaction')->count());
    }
}
