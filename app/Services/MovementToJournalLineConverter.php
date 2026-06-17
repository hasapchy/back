<?php

namespace App\Services;

use App\DTO\JournalEntryLineDraft;
use App\Enums\FinancialAccountMovementDirection;
use App\Enums\FinancialAccountType;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountMovement;

class MovementToJournalLineConverter
{
    /**
     * @param  FinancialAccountMovement  $movement
     * @param  FinancialAccount  $account
     * @return JournalEntryLineDraft
     */
    public function convert(FinancialAccountMovement $movement, FinancialAccount $account): JournalEntryLineDraft
    {
        $amount = abs((float) $movement->delta);
        $isDebit = $this->isDebitForAccountType($account, $movement->direction);

        return new JournalEntryLineDraft(
            accountCode: $account->code,
            debit: $isDebit ? $amount : 0,
            credit: $isDebit ? 0 : $amount,
            meta: array_filter([
                'project_id' => $movement->project_id,
                'client_id' => $movement->client_id,
                'legacy_movement_id' => $movement->id,
            ]),
        );
    }

    /**
     * @param  FinancialAccount  $account
     * @param  FinancialAccountMovementDirection  $direction
     * @return bool
     */
    public function isDebitForAccountType(FinancialAccount $account, FinancialAccountMovementDirection $direction): bool
    {
        $increase = $direction === FinancialAccountMovementDirection::Increase;

        if ($account->is_contra) {
            return ! $increase;
        }

        return match ($account->type) {
            FinancialAccountType::Asset, FinancialAccountType::Expense => $increase,
            FinancialAccountType::Liability, FinancialAccountType::Income, FinancialAccountType::Equity => ! $increase,
        };
    }
}
