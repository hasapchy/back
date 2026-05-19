<?php

namespace App\Http\Requests;

use App\Support\CompanyScopedPermissions;
use App\Support\ResolvedCompany;
use Illuminate\Validation\Rule;

class UpdateUnitRequest extends StoreUnitRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ResolvedCompany::fromRequest($this) !== null
            && CompanyScopedPermissions::userHas($user, 'settings_units_edit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cid = ResolvedCompany::fromRequest($this);
        $unitId = (int) $this->route('id');

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('units', 'name')->where(fn ($q) => $q->where('company_id', $cid))->ignore($unitId),
            ],
            'short_name' => [
                'required',
                'string',
                'max:64',
                Rule::unique('units', 'short_name')->where(fn ($q) => $q->where('company_id', $cid))->ignore($unitId),
            ],
        ];
    }
}
