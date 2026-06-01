<?php

namespace App\Support;

use Illuminate\Http\Request;

class ResolvedCompany
{
    public const ATTRIBUTE = 'resolved_company_id';

    public const SESSION_KEY = 'current_company_id';

    /**
     * @return int|null
     */
    public static function fromRequest(?Request $request = null): ?int
    {
        $request = $request ?? request();

        if (! $request->attributes->has(self::ATTRIBUTE)) {
            return null;
        }

        $value = $request->attributes->get(self::ATTRIBUTE);
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public static function bindToRequest(Request $request, int $companyId): void
    {
        $request->attributes->set(self::ATTRIBUTE, $companyId);
    }
}
