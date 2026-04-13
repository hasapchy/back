<?php

namespace App\Http\Requests\Concerns;

trait NormalizesOrderNullableTextFields
{
    /**
     * Пустые строки description / note → null.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $data = $this->all();

        foreach (['description', 'note'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && trim($data[$field]) === '') {
                $data[$field] = null;
            }
        }

        $this->merge($data);
    }
}
