<?php

namespace App\Http\Requests\Concerns;

use App\Rules\CashRegisterAccessRule;
use App\Rules\ClientAccessRule;
use App\Rules\ProjectAccessRule;

trait SharedOrderEntityRules
{
    protected function sharedOrderEntityRules(bool $isSimpleUser, bool $forUpdate): array
    {
        $projectRules = $isSimpleUser
            ? ($forUpdate
                ? ['nullable', 'sometimes', 'integer', 'exists:projects,id']
                : ['nullable', 'integer', 'exists:projects,id'])
            : ($forUpdate
                ? ['nullable', 'sometimes', 'integer', new ProjectAccessRule()]
                : ['nullable', 'integer', new ProjectAccessRule()]);

        return [
            'client_id' => $isSimpleUser
                ? ['required', 'integer', 'exists:clients,id']
                : ['required', 'integer', new ClientAccessRule()],
            'project_id' => $projectRules,
            'cash_id' => $isSimpleUser
                ? ['nullable', 'integer', 'exists:cash_registers,id']
                : ['required', 'integer', new CashRegisterAccessRule()],
        ];
    }
}
