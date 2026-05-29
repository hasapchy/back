<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesTransactionCategoryType;
use App\Http\Requests\Concerns\ValidatesTransactionClientBalanceConsistency;
use App\Models\Order;
use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Rules\ProjectAccessRule;
use App\Rules\ClientAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateTransactionRequest extends FormRequest
{
    use ValidatesTransactionClientBalanceConsistency;
    use ValidatesTransactionCategoryType;

    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $transaction = Transaction::query()->find($this->route('id'));

        if (! $transaction) {
            return true;
        }

        return $this->user()->can('update', $transaction);
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:transaction_categories,id',
            'project_id' => 'nullable|sometimes|exists:projects,id',
            'client_id' => 'nullable|sometimes|exists:clients,id',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date',
            'orig_amount' => 'nullable|sometimes|numeric|gt:0',
            'currency_id' => 'nullable|sometimes|exists:currencies,id',
            'is_debt' => 'nullable|boolean',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer',
            'order_id' => 'nullable|integer|exists:orders,id',
            'exchange_rate' => 'nullable|numeric|min:0.00001',
        ];
    }

    /**
     * Настроить валидатор
     *
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $transactionId = $this->route('id');
            if ($transactionId) {
                $transaction = Transaction::find($transactionId);
                if ($transaction && $transaction->client_balance_id) {
                    $currencyId = $this->has('currency_id')
                        ? $this->input('currency_id')
                        : $transaction->currency_id;
                    $clientId = $this->has('client_id')
                        ? $this->input('client_id')
                        : $transaction->client_id;
                    $this->assertTransactionPayloadMatchesClientBalance(
                        $validator,
                        $transaction->client_balance_id,
                        $clientId,
                        $currencyId,
                        $transaction->cash_id,
                    );
                }
            }

            $sourceType = $this->input('source_type');
            $sourceId = $this->input('source_id');
            $projectId = $this->input('project_id');
            if (!$projectId && $this->route('id')) {
                $transaction = Transaction::find($this->route('id'));
                if ($transaction) {
                    $projectId = $transaction->project_id;
                }
            }
            if ($sourceType && $sourceId && str_contains($sourceType, 'ProjectContract')) {
                $contract = ProjectContract::find($sourceId);
                if (!$contract) {
                    $validator->errors()->add('source_id', __('Контракт не найден.'));
                } elseif ($projectId && (int) $contract->project_id !== (int) $projectId) {
                    $validator->errors()->add('source_id', __('Контракт не принадлежит выбранному проекту.'));
                }
            }

            $transaction = $transactionId ? Transaction::find($transactionId) : null;
            $orderId = $this->input('order_id');
            $sourceType = $this->input('source_type') ?? $transaction?->source_type;
            $sourceId = $this->input('source_id') ?? $transaction?->source_id;
            if (($orderId === null || $orderId === '') && $sourceType && str_contains((string) $sourceType, 'Order') && $sourceId) {
                $orderId = $sourceId;
            }
            $balanceId = $this->has('client_balance_id')
                ? $this->input('client_balance_id')
                : $transaction?->client_balance_id;
            $isDebt = $this->has('is_debt')
                ? $this->requestBool($this->input('is_debt'))
                : (bool) ($transaction?->is_debt ?? false);
            $categoryId = $this->has('category_id')
                ? $this->normalizeOptionalInt($this->input('category_id'))
                : ($transaction?->category_id !== null ? (int) $transaction->category_id : null);
            $this->assertParentDocumentClientBalance(
                $validator,
                $orderId,
                $sourceType,
                $sourceId,
                $balanceId,
                $isDebt,
                $categoryId,
            );

            $transactionType = $transaction?->type !== null ? (int) $transaction->type : null;
            $this->assertTransactionCategoryMatchesType(
                $validator,
                $transactionType,
                $categoryId,
            );

            if (
                $transaction
                && $this->has('orig_amount')
                && ! $isDebt
                && $sourceType
                && $sourceId
                && str_contains((string) $sourceType, 'ProjectContract')
            ) {
                $contract = ProjectContract::find($sourceId);
                if ($contract) {
                    $remaining = max(0, (float) $contract->amount - (float) $contract->paid_amount + (float) $transaction->orig_amount);
                    if ((float) $this->input('orig_amount') > $remaining + 0.01) {
                        $validator->errors()->add('orig_amount', __('project_contract.payment_exceeds_remaining'));
                    }
                }
            }
        });
    }

    /**
     * Обработать неудачную валидацию
     *
     * @param Validator $validator
     * @return void
     */
    protected function failedValidation(Validator $validator)
    {
        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}

