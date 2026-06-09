<?php

namespace Tests\Support\Concerns;

use App\Models\Company;
use App\Models\User;

trait ActsAsApiUser
{
    /**
     * @return $this
     */
    protected function actingAsApi(User $user, Company|int|null $company = null): self
    {
        $companyId = $company instanceof Company ? (int) $company->id : $company;

        return $this->withApiTokenForCompany($user, $companyId);
    }
}
