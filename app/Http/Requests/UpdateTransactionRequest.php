<?php

namespace App\Http\Requests;

use App\Models\ProjectContract;
use App\Models\Transaction;
use App\Rules\ProjectAccessRule;
use App\Rules\ClientAccessRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateTransactionRequest extends FormRequest
{
    /**
     * Определить, авторизован ли пользователь для выполнения этого запроса
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
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
            'orig_amount' => 'nullable|sometimes|numeric|min:0.01',
            'currency_id' => 'nullable|sometimes|exists:currencies,id',
            'is_debt' => 'nullable|boolean',
            'source_type' => 'nullable|string',
            'source_id' => 'nullable|integer',
            'exchange_rate' => 'nullable|numeric|min:0.000001',
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
            $sourceType = $this->input('source_type');
            $sourceId = $this->input('source_id');
            $projectId = $this->input('project_id');
            if (!$projectId && $this->route('id')) {
                $transaction = Transaction::find($this->route('id'));
                if ($transaction) {
                    $projectId = $transaction->project_id;
                }
            }
            if (!$sourceType || !$sourceId || strpos($sourceType, 'ProjectContract') === false) {
                return;
            }
            $contract = ProjectContract::find($sourceId);
            if (!$contract) {
                $validator->errors()->add('source_id', __('Контракт не найден.'));
                return;
            }
            if ($projectId && (int) $contract->project_id !== (int) $projectId) {
                $validator->errors()->add('source_id', __('Контракт не принадлежит выбранному проекту.'));
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

