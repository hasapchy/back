<?php

namespace App\Http\Requests;

use App\Enums\ProjectContractStatus;
use App\Models\ProjectContract;
use App\Models\Project;
use App\Rules\CashRegisterTypeMatchRule;
use App\Rules\ProjectAccessRule;
use App\Support\ProjectContractActivationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PatchProjectContractRequest extends FormRequest
{
    private const PATCH_KEYS = [
        'project_id',
        'status',
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

    /**
     * @return bool
     */
    public function authorize(): bool
    {
        $contract = ProjectContract::query()->find($this->route('id'));

        if (! $contract) {
            return true;
        }

        return $this->user()->can('update', $contract);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $contractId = $this->route('id');
        $contract = $contractId ? ProjectContract::query()->with('project:id,client_id')->find($contractId) : null;
        $effectiveProjectId = $this->resolveEffectiveProjectId($contract);
        $projectClientId = $effectiveProjectId
            ? Project::query()->whereKey($effectiveProjectId)->value('client_id')
            : null;
        $effectiveType = $this->has('type')
            ? (int) $this->input('type')
            : (int) ($contract?->type ?? 1);

        return [
            'project_id' => ['sometimes', 'integer', 'exists:projects,id', new ProjectAccessRule()],
            'status' => ['sometimes', 'string', Rule::in(ProjectContractStatus::values())],
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

    /**
     * @param Validator $validator
     * @return void
     */
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

            $currentStatus = $contract->contractStatus();
            $incomingStatus = $this->has('status')
                ? ProjectContractActivationRules::resolveStatus($this->input('status'))
                : $currentStatus;

            if ($currentStatus === ProjectContractStatus::Active && $incomingStatus === ProjectContractStatus::Draft) {
                $validator->errors()->add('status', __('Нельзя вернуть активный контракт в черновик.'));

                return;
            }

            $effectiveProjectId = $this->resolveEffectiveProjectId($contract);
            $projectClientId = $effectiveProjectId
                ? Project::query()->whereKey($effectiveProjectId)->value('client_id')
                : null;

            if ($this->has('project_id') && $currentStatus !== ProjectContractStatus::Draft) {
                $targetProjectId = $this->input('project_id');
                $targetProject = $targetProjectId
                    ? Project::query()->select(['id', 'client_id', 'currency_id'])->find((int) $targetProjectId)
                    : null;
                $currentContractClientId = $contract->client_id ?? $contract->project?->client_id;
                if ($targetProject && $currentContractClientId && (int) $targetProject->client_id !== (int) $currentContractClientId) {
                    $validator->errors()->add('project_id', __('Проект должен быть этого же клиента.'));
                }

                $effectiveCurrencyId = $this->has('currency_id')
                    ? (int) $this->input('currency_id')
                    : (int) ($contract->currency_id ?? 0);
                if ($targetProject && $effectiveCurrencyId > 0 && (int) ($targetProject->currency_id ?? 0) !== $effectiveCurrencyId) {
                    $validator->errors()->add('project_id', __('Проект должен быть в той же валюте, что и контракт.'));
                }
            }

            if ($currentStatus === ProjectContractStatus::Draft && $incomingStatus === ProjectContractStatus::Active) {
                $merged = ProjectContractActivationRules::mergeEffectiveFields(
                    $this->only(self::PATCH_KEYS),
                    $contract->only(['project_id', 'client_id', 'number', 'type', 'amount', 'currency_id', 'cash_id', 'client_balance_id', 'date', 'returned', 'note'])
                );
                ProjectContractActivationRules::validateActiveFields($validator, $merged, $effectiveProjectId);
            } elseif ($currentStatus === ProjectContractStatus::Active) {
                $type = $this->has('type') ? (int) $this->input('type') : (int) $contract->type;
                $number = $this->has('number') ? $this->input('number') : $contract->number;
                if ($type === 0 && (! is_string($number) || trim((string) $number) === '')) {
                    $validator->errors()->add('number', __('Для безналичного контракта укажите номер.'));
                }

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
            }
        });
    }

    /**
     * @param  ProjectContract|null  $contract
     * @return int|null
     */
    private function resolveEffectiveProjectId(?ProjectContract $contract): ?int
    {
        if ($this->has('project_id')) {
            $projectId = $this->input('project_id');
            if ($projectId === null || $projectId === '') {
                return null;
            }

            return (int) $projectId;
        }

        return $contract?->project_id ? (int) $contract->project_id : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cash_id.exists' => 'Укажите кассу.',
        ];
    }

    /**
     * @return void
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('returned')) {
            $this->merge([
                'returned' => filter_var($this->input('returned'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }

    /**
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
