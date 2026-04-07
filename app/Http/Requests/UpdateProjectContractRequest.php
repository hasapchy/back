<?php

namespace App\Http\Requests;

use App\Models\ProjectContract;
use App\Rules\CashRegisterTypeMatchRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateProjectContractRequest extends FormRequest
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
        $projectClientId = null;
        $contractId = $this->route('id');
        if ($contractId) {
            $contract = ProjectContract::query()->with('project:id,client_id')->find($contractId);
            $projectClientId = $contract?->project?->client_id;
        }
        $contractType = $this->input('type');

        return [
            'client_id' => [
                Rule::requiredIf(fn () => (bool) $projectClientId),
                'nullable',
                'integer',
                'exists:clients,id',
            ],
            'number' => 'required_if:type,0|nullable|string|max:255',
            'type' => 'required|integer|in:0,1',
            'amount' => 'required|numeric|min:0',
            'currency_id' => 'nullable|exists:currencies,id',
            'cash_id' => ['required', 'exists:cash_registers,id', new CashRegisterTypeMatchRule()],
            'client_balance_id' => [
                'nullable',
                'integer',
                Rule::exists('client_balances', 'id')->where(function ($q) use ($projectClientId, $contractType) {
                    if ($projectClientId) {
                        $q->where('client_id', $projectClientId);
                    } else {
                        $q->whereRaw('1 = 0');
                    }
                    if ($contractType !== null && $contractType !== '') {
                        $q->where('type', (int) $contractType);
                    }
                }),
            ],
            'date' => 'required|date',
            'returned' => 'nullable|boolean',
            'files' => 'nullable|array',
            'note' => 'nullable|string',
        ];
    }

    /**
     * @param Validator $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $contractId = $this->route('id');
            $contract = $contractId ? ProjectContract::query()->with('project:id,client_id')->find($contractId) : null;
            $projectClientId = $contract?->project?->client_id;
            $clientId = $this->input('client_id');
            if ($projectClientId) {
                if ((int) $clientId !== (int) $projectClientId) {
                    $validator->errors()->add('client_id', __('Клиент должен совпадать с клиентом проекта.'));
                }
            } elseif ($clientId !== null && $clientId !== '') {
                $validator->errors()->add('client_id', __('У проекта не указан клиент.'));
            }
        });
    }

    /**
     * Сообщения валидации
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cash_id.required' => 'Укажите кассу.',
        ];
    }

    /**
     * Подготовить данные для валидации
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        if (isset($data['returned'])) {
            $data['returned'] = filter_var($data['returned'], FILTER_VALIDATE_BOOLEAN);
        }

        $this->merge($data);
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
