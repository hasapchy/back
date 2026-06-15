<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Order;
use App\Models\Project;
use App\Models\Transaction;
use App\Models\WhReceipt;

class ProjectBalanceCalculator
{
    /**
     * @return array{
     *     is_report_currency: bool,
     *     is_default_currency: bool,
     *     amount_field: string,
     *     default_currency: Currency|null,
     *     report_currency: Currency|null
     * }
     */
    public function resolveCurrencyContext(?Project $project, ?int $companyId = null): array
    {
        $companyId = $companyId ?? ($project?->company_id ? (int) $project->company_id : null);
        $projectCurrency = $project && $project->currency_id ? Currency::find($project->currency_id) : null;

        $defaultCurrency = Currency::query()
            ->where('is_default', true)
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->first();

        $reportCurrency = Currency::query()
            ->where('is_report', true)
            ->where(function ($query) use ($companyId) {
                $query->where('company_id', $companyId)->orWhereNull('company_id');
            })
            ->first();

        $isReportCurrency = $projectCurrency && $reportCurrency && $projectCurrency->id === $reportCurrency->id;
        $isDefaultCurrency = $projectCurrency && $defaultCurrency && $projectCurrency->id === $defaultCurrency->id;

        $amountField = 'orig_amount';
        if ($isReportCurrency) {
            $amountField = 'rep_amount';
        } elseif ($isDefaultCurrency) {
            $amountField = 'def_amount';
        }

        return [
            'is_report_currency' => $isReportCurrency,
            'is_default_currency' => $isDefaultCurrency,
            'amount_field' => $amountField,
            'default_currency' => $defaultCurrency,
            'report_currency' => $reportCurrency,
        ];
    }

    /**
     * @return array{source: string, base_amount: float, signed_amount: float, amount_field: string}
     */
    public function computeSignedAmount(
        Transaction $transaction,
        bool $isReportCurrency,
        bool $isDefaultCurrency
    ): array {
        $source = $this->mapTransactionSource($transaction);

        if ($isReportCurrency) {
            $amount = $transaction->rep_amount ?? $transaction->orig_amount;
            $amountField = $transaction->rep_amount !== null ? 'rep_amount' : 'orig_amount';
        } elseif ($isDefaultCurrency) {
            $amount = $transaction->def_amount ?? $transaction->orig_amount;
            $amountField = $transaction->def_amount !== null ? 'def_amount' : 'orig_amount';
        } else {
            $amount = $transaction->orig_amount;
            $amountField = 'orig_amount';
        }

        $baseAmount = (float) $amount;
        $signedAmount = $this->resolveBalanceContribution($transaction, $baseAmount, $source);

        return [
            'source' => $source,
            'base_amount' => $baseAmount,
            'signed_amount' => $signedAmount,
            'amount_field' => $amountField,
        ];
    }

    /**
     * @return float
     */
    public function resolveBalanceContribution(Transaction $transaction, float $amount, ?string $source = null): float
    {
        $source ??= $this->mapTransactionSource($transaction);

        return match ($source) {
            'receipt' => -$amount,
            'transaction' => $transaction->type == 1 ? +$amount : -$amount,
            'sale' => +$amount,
            'order' => -$amount,
        };
    }

    /**
     * @return string
     */
    public function mapTransactionSource(Transaction $transaction): string
    {
        return match ($transaction->source_type) {
            'App\\Models\\Sale' => 'sale',
            Order::class => 'order',
            WhReceipt::class => 'receipt',
            default => 'transaction',
        };
    }

    /**
     * @return float
     */
    public function resolveStoredBalanceAmount(Transaction $transaction, string $amountField): float
    {
        return match ($amountField) {
            'rep_amount' => (float) ($transaction->rep_amount ?? $transaction->orig_amount),
            'def_amount' => (float) ($transaction->def_amount ?? $transaction->orig_amount),
            default => (float) $transaction->orig_amount,
        };
    }
}
