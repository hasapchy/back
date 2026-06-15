<?php

namespace App\Services;

class OrderPaymentStatusService
{
    private const EPSILON = 0.00001;

    /**
     * @return array{payment_status: string, payment_status_text: string}
     */
    public function resolve(float $paidAmount, float $defTotalPrice): array
    {
        $paidAmount = max(0.0, $paidAmount);
        $defTotalPrice = max(0.0, $defTotalPrice);

        if ($paidAmount <= 0) {
            return [
                'payment_status' => 'unpaid',
                'payment_status_text' => 'Не оплачено',
            ];
        }

        if ($paidAmount + self::EPSILON < $defTotalPrice) {
            return [
                'payment_status' => 'partially_paid',
                'payment_status_text' => 'Частично оплачено',
            ];
        }

        return [
            'payment_status' => 'paid',
            'payment_status_text' => 'Оплачено',
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $order
     * @return void
     */
    public function applyToOrder($order, float $paidAmount): void
    {
        $defTotalPrice = (float) ($order->def_total_price ?? 0);
        $resolved = $this->resolve($paidAmount, $defTotalPrice);

        $order->setAttribute('paid_amount', $paidAmount);
        $order->setAttribute('payment_status', $resolved['payment_status']);
        $order->setAttribute('payment_status_text', $resolved['payment_status_text']);
        $order->makeVisible(['paid_amount', 'payment_status', 'payment_status_text']);
    }
}
