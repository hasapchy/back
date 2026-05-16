<?php

namespace App\Support;

use App\Enums\ProjectContractStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;

class ProjectContractActivationRules
{
    /**
     * @param  mixed  $value
     */
    public static function resolveStatus($value): ProjectContractStatus
    {
        if ($value === null || $value === '') {
            return ProjectContractStatus::Draft;
        }

        return ProjectContractStatus::tryFrom((string) $value) ?? ProjectContractStatus::Draft;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function validateActiveFields(Validator $validator, array $data, ?int $projectId): void
    {
        $project = $projectId ? Project::query()->select('id', 'client_id')->find($projectId) : null;
        $projectClientId = $project?->client_id;
        $type = array_key_exists('type', $data) ? (int) $data['type'] : null;

        if ($type === null) {
            $validator->errors()->add('type', __('validation.required', ['attribute' => 'type']));
        } elseif (! in_array($type, [0, 1], true)) {
            $validator->errors()->add('type', __('validation.in', ['attribute' => 'type']));
        }

        if (! array_key_exists('amount', $data) || $data['amount'] === null || $data['amount'] === '') {
            $validator->errors()->add('amount', __('validation.required', ['attribute' => 'amount']));
        }

        if (! array_key_exists('cash_id', $data) || $data['cash_id'] === null || $data['cash_id'] === '') {
            $validator->errors()->add('cash_id', __('Укажите кассу.'));
        }

        if (! array_key_exists('date', $data) || $data['date'] === null || $data['date'] === '') {
            $validator->errors()->add('date', __('validation.required', ['attribute' => 'date']));
        }

        if ($type === 0) {
            $number = $data['number'] ?? null;
            if (! is_string($number) || trim($number) === '') {
                $validator->errors()->add('number', __('Для безналичного контракта укажите номер.'));
            }
        }

        if ($projectClientId) {
            $clientId = $data['client_id'] ?? null;
            if ($clientId === null || $clientId === '') {
                $validator->errors()->add('client_id', __('validation.required', ['attribute' => 'client_id']));
            } elseif ((int) $clientId !== (int) $projectClientId) {
                $validator->errors()->add('client_id', __('Клиент должен совпадать с клиентом проекта.'));
            }
        } elseif (array_key_exists('client_id', $data) && $data['client_id'] !== null && $data['client_id'] !== '') {
            $validator->errors()->add('client_id', __('У проекта не указан клиент.'));
        }
    }

    /**
     * @param  array<string, mixed>  $patchData
     * @param  array<string, mixed>  $contractAttributes
     * @return array<string, mixed>
     */
    public static function mergeEffectiveFields(array $patchData, array $contractAttributes): array
    {
        $keys = ['project_id', 'client_id', 'number', 'type', 'amount', 'currency_id', 'cash_id', 'client_balance_id', 'date', 'returned', 'note'];

        $merged = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $patchData)) {
                $merged[$key] = $patchData[$key];
            } elseif (array_key_exists($key, $contractAttributes)) {
                $merged[$key] = $contractAttributes[$key];
            }
        }

        return $merged;
    }
}
