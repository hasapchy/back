<?php

namespace App\Services;

use App\Models\FinancialAccountMovement;
use App\Models\Transaction;
use App\Services\Financial\FinancialAccountVerificationResult;
use Illuminate\Support\Facades\DB;

class FinancialAccountVerifierService
{
    public function __construct(
        private readonly FinancialAccountService $financialAccountService,
        private readonly FinancialAccountRuleResolver $ruleResolver,
    ) {}

    /**
     * @param  int  $accountId
     * @param  int|null  $companyId
     * @return FinancialAccountVerificationResult
     */
    public function verifyAccount(int $accountId, ?int $companyId = null): FinancialAccountVerificationResult
    {
        $errors = [];

        $query = FinancialAccountMovement::query()
            ->active()
            ->where('financial_account_id', $accountId);

        if ($companyId) {
            $query->where('company_id', $companyId);
        }

        $deltaSum = round((float) (clone $query)->sum('delta'), 5);
        $reported = $this->financialAccountService->getBalance($accountId, null, $companyId);

        if (abs($deltaSum - $reported) > 0.00001) {
            $errors[] = "Account {$accountId}: balance mismatch (delta_sum={$deltaSum}, reported={$reported})";
        }

        $lastMovement = (clone $query)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->first(['balance_after', 'delta']);

        if ($lastMovement && round((float) $lastMovement->balance_after, 5) !== $reported) {
            $errors[] = "Account {$accountId}: last balance_after ({$lastMovement->balance_after}) != reported ({$reported})";
        }

        $nullBalanceAfter = (clone $query)->whereNull('balance_after')->exists();
        if ($nullBalanceAfter) {
            $errors[] = "Account {$accountId}: movements with null balance_after found";
        }

        $duplicateHashes = FinancialAccountMovement::query()
            ->active()
            ->select('movement_hash', DB::raw('COUNT(*) as cnt'))
            ->groupBy('movement_hash')
            ->having('cnt', '>', 1)
            ->pluck('movement_hash');

        if ($duplicateHashes->isNotEmpty()) {
            $errors[] = 'Duplicate active movement_hash values: '.$duplicateHashes->implode(', ');
        }

        return $errors === [] ? FinancialAccountVerificationResult::pass() : new FinancialAccountVerificationResult(false, $errors);
    }

    /**
     * @param  int  $transactionId
     * @return FinancialAccountVerificationResult
     */
    public function verifyTransaction(int $transactionId): FinancialAccountVerificationResult
    {
        $errors = [];
        $transaction = Transaction::query()->find($transactionId);

        if (! $transaction) {
            return FinancialAccountVerificationResult::fail("Transaction {$transactionId} not found");
        }

        if ($transaction->is_deleted) {
            $orphans = FinancialAccountMovement::query()
                ->active()
                ->where('transaction_id', $transactionId)
                ->count();

            if ($orphans > 0) {
                $errors[] = "Transaction {$transactionId} is deleted but has {$orphans} active movements";
            }

            return $errors === [] ? FinancialAccountVerificationResult::pass() : new FinancialAccountVerificationResult(false, $errors);
        }

        $expectedRules = $this->ruleResolver->resolve($transaction);
        $activeMovements = FinancialAccountMovement::query()
            ->active()
            ->where('transaction_id', $transactionId)
            ->get();

        if ($expectedRules->count() !== $activeMovements->count()) {
            $errors[] = "Transaction {$transactionId}: expected {$expectedRules->count()} movements, found {$activeMovements->count()}";
        }

        foreach ($expectedRules as $rule) {
            $hash = $this->financialAccountService->buildMovementHash(
                (int) $rule->financial_account_id,
                $transactionId,
                (int) $rule->id,
                $rule->direction->value
            );

            if (! $activeMovements->contains('movement_hash', $hash)) {
                $errors[] = "Transaction {$transactionId}: missing movement for rule {$rule->id}";
            }
        }

        return $errors === [] ? FinancialAccountVerificationResult::pass() : new FinancialAccountVerificationResult(false, $errors);
    }
}
