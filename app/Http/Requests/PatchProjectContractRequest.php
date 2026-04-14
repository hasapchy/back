<?php

namespace App\Http\Requests;

use App\Models\ProjectContract;
use App\Rules\CashRegisterTypeMatchRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PatchProjectContractRequest extends FormRequest
{
    private const PATCH_KEYS = [
        'client_id',
        'number',
        'type',
        'amount',
        'currency_id',
        'cash_id',
        'client_balance_id',
        'date',
        'returned',
        'files',
        'note',
    ];

    public function authorize(): bool
    {
        $contract = ProjectContract::query()->find($this->route('id'));

        if (! $contract) {
            return true;
        }

        return $this->user()->can('update', $contract);
    }

    public function rules(): array
    {
        $contractId = $this->route('id');
        $contract = $contractId ? ProjectContract::query()->with('project:id,client_id')->find($contractId) : null;
        $projectClientId = $contract?->project?->client_id;
        $effectiveType = $this->has('type')
            ? (int) $this->input('type')
            : (int) ($contract?->type ?? 1);

        return [
            'client_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:clients,id',
            ],
            'number' => 'sometimes|nullable|string|max:255',
            'type' => 'sometimes|integer|in:0,1',
            'amount' => 'sometimes|numeric|min:0',
            'currency_id' => 'sometimes|nullable|exists:currencies,id',
            'cash_id' => ['sometimes', 'nullable', 'exists:cash_registers,id', new CashRegisterTypeMatchRule($effectiveType)],
            'client_balance_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('client_balances', 'id')->where(function ($q) use ($projectClientId, $effectiveType) {
                    if ($projectClientId) {
                        $q->where('client_id', $projectClientId);
                    } else {
                        $q->whereRaw('1 = 0');
                    }
                    $q->where('type', $effectiveType);
                }),
            ],
            'date' => 'sometimes|date',
            'returned' => 'sometimes|boolean',
            'files' => 'sometimes|nullable|array',
            'note' => 'sometimes|nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasPatchField = false;
            foreach (self::PATCH_KEYS as $key) {
                if ($this->has($key)) {
                    $hasPatchField = true;
                    break;
                }
            }
            if (! $hasPatchField) {
                $validator->errors()->add('body', __('Укажите хотя бы одно поле для обновления.'));

                return;
            }

            $contractId = $this->route('id');
            $contract = $contractId ? ProjectContract::query()->with('project:id,client_id')->find($contractId) : null;
            if (! $contract) {
                return;
            }

            $projectClientId = $contract->project?->client_id;
            if ($this->has('client_id')) {
                $clientId = $this->input('client_id');
                if ($projectClientId) {
                    if ((int) $clientId !== (int) $projectClientId) {
                        $validator->errors()->add('client_id', __('Клиент должен совпадать с клиентом проекта.'));
                    }
                } elseif ($clientId !== null && $clientId !== '') {
                    $validator->errors()->add('client_id', __('У проекта не указан клиент.'));
                }
            }

            $type = $this->has('type') ? (int) $this->input('type') : (int) $contract->type;
            $number = $this->has('number') ? $this->input('number') : $contract->number;
            if ($type === 0 && (! is_string($number) || trim($number) === '')) {
                $validator->errors()->add('number', __('Для безналичного контракта укажите номер.'));
            }
        });
    }

    public function messages(): array
    {
        return [
            'cash_id.exists' => 'Укажите кассу.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('returned')) {
            $this->merge([
                'returned' => filter_var($this->input('returned'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    protected function failedValidation(Validator $validator)
    {
        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
