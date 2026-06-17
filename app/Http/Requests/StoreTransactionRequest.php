<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesTransactionCategoryType;
use App\Http\Requests\Concerns\ValidatesTransactionClientBalanceConsistency;
use App\Http\Requests\Concerns\ValidatesTransactionDocumentPaymentAmount;
use App\Models\Transaction;
use App\Rules\CashRegisterAccessRule;
use App\Rules\ProjectAccessRule;
use App\Rules\ClientAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class StoreTransactionRequest extends FormRequest
{
    use ValidatesTransactionClientBalanceConsistency;
    use ValidatesTransactionCategoryType;
    use ValidatesTransactionDocumentPaymentAmount;

    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', Transaction::class);
    }

    /**
     * Получить правила валидации
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'type' => 'required|integer|in:1,0',
            'orig_amount' => 'required|numeric|gt:0',
            'currency_id' => 'required|exists:currencies,id',
            'cash_id' => ['required', 'integer', new CashRegisterAccessRule()],
            'category_id' => 'required|exists:transaction_categories,id',
            'project_id' => ['nullable', 'sometimes', new ProjectAccessRule()],
            'client_id' => ['nullable', 'sometimes', new ClientAccessRule()],
            'client_balance_id' => 'nullable|integer|exists:client_balances,id',
            'order_id' => 'nullable|integer|exists:orders,id',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer',
            'note' => 'nullable|sometimes|string',
            'date' => 'nullable|sometimes|date',
            'is_debt' => 'nullable|boolean',
            'is_adjustment' => 'nullable|boolean',
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
            $this->assertTransactionPayloadMatchesClientBalance(
                $validator,
                $this->input('client_balance_id'),
                $this->input('client_id'),
                $this->input('currency_id'),
                $this->input('cash_id'),
            );

            $this->assertParentDocumentClientBalance(
                $validator,
                $this->input('order_id'),
                $this->input('source_type'),
                $this->input('source_id'),
                $this->input('client_balance_id'),
                $this->requestBool($this->input('is_debt')),
                $this->normalizeOptionalInt($this->input('category_id')),
            );

            if (! $this->requestBool($this->input('is_adjustment'))) {
                $this->assertTransactionCategoryMatchesType(
                    $validator,
                    $this->normalizeOptionalInt($this->input('type')),
                    $this->normalizeOptionalInt($this->input('category_id')),
                );
            }

            $sourceType = $this->input('source_type');
            $sourceId = $this->normalizeOptionalInt($this->input('source_id'));
            $orderId = $this->normalizeOptionalInt($this->input('order_id'));
            $isDebt = $this->requestBool($this->input('is_debt'));
            $origAmount = (float) $this->input('orig_amount');
            $currencyId = (int) $this->input('currency_id');
            $transactionType = $this->normalizeOptionalInt($this->input('type'));

            $this->assertContractPaymentWithinRemaining(
                $validator,
                $sourceType ? (string) $sourceType : null,
                $sourceId,
                $this->normalizeOptionalInt($this->input('project_id')),
                $origAmount,
                $isDebt,
            );

            $this->assertOrderPaymentWithinRemaining(
                $validator,
                $orderId,
                $sourceType ? (string) $sourceType : null,
                $sourceId,
                $origAmount,
                $currencyId,
                $isDebt,
                $transactionType,
                null,
                $this->input('date'),
            );
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

