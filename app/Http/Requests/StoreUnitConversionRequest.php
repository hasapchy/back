<?php

namespace App\Http\Requests;

use App\Support\CompanyScopedPermissions;
use App\Support\ResolvedCompany;
use Illuminate\Foundation\Http\FormRequest;

class StoreUnitConversionRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ResolvedCompany::fromRequest($this) !== null
            && CompanyScopedPermissions::userHas($user, 'settings_units_manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'parent_unit_id' => ['required', 'integer', 'exists:units,id'],
            'child_unit_id' => ['required', 'integer', 'exists:units,id', 'different:parent_unit_id'],
            'quantity' => ['required', 'numeric', 'min:0.00001'],
        ];
    }
}
