<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * @return $this
     */
    protected function withApiTokenForCompany(User $user, ?int $companyId): self
    {
        $issued = $user->createToken('test-token', ['*']);
        if ($companyId !== null) {
            $issued->accessToken->forceFill(['company_id' => $companyId])->save();
        }

        return $this->withHeader('Authorization', 'Bearer '.$issued->plainTextToken);
    }
}
