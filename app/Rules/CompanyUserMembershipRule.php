<?php

namespace App\Rules;

use App\Support\ResolvedCompany;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class CompanyUserMembershipRule implements ValidationRule
{
    /**
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  Closure(string): void  $fail
     * @return void
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $companyId = ResolvedCompany::fromRequest(request());
        if (! $companyId) {
            $fail(__('api.common.company_context_required'));

            return;
        }

        $exists = DB::table('company_user')
            ->where('company_id', $companyId)
            ->where('user_id', (int) $value)
            ->exists();

        if (! $exists) {
            $fail(__('api.common.chat_user_not_in_company'));
        }
    }
}
