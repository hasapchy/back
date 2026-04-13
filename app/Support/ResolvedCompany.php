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

        if ($request->attributes->has(self::ATTRIBUTE)) {
            $v = $request->attributes->get(self::ATTRIBUTE);

            if ($v === null || $v === '') {
                return null;
            }

            return (int) $v;
        }

        return self::fromHeaderOnly($request);
    }

    /**
     * @return int|null
     */
    public static function fromHeaderOnly(Request $request): ?int
    {
        $companyId = $request->header('X-Company-ID');
        if ($companyId === null || $companyId === '' || ! is_numeric($companyId)) {
            return null;
        }

        return (int) $companyId;
    }
}
