<?php

namespace App\Http\Requests\Concerns;

use App\Services\RoundingModuleRegistry;

trait ValidatesCompanyRoundingFields
{
    /**
     * @return array<string, string>
     */
    protected function roundingModuleValidationRules(): array
    {
        return (new RoundingModuleRegistry)->validationRules();
    }

    /**
     * @return array<int, string>
     */
    protected function roundingModuleBooleanFields(): array
    {
        return array_merge(
            ['rounding_enabled'],
            (new RoundingModuleRegistry)->moduleEnabledFields(),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function normalizeRoundingFields(array $data): array
    {
        foreach ($this->roundingModuleBooleanFields() as $field) {
            if (isset($data[$field])) {
                $data[$field] = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (isset($data['rounding_custom_threshold']) && $data['rounding_custom_threshold'] === '') {
            $data['rounding_custom_threshold'] = null;
        }

        if (isset($data['rounding_enabled']) && ! $data['rounding_enabled']) {
            $data['rounding_direction'] = null;
            $data['rounding_custom_threshold'] = null;

            foreach ((new RoundingModuleRegistry)->moduleEnabledFields() as $field) {
                $data[$field] = false;
            }
        }

        return $data;
    }
}
