<?php

namespace App\Support;

use App\Models\Client;
use Carbon\CarbonInterface;

class ClientReferencePayload
{
    /**
     * @param  mixed  $client
     * @return array<string, mixed>
     */
    public static function build($client, ?int $companyId = null): array
    {
        $balance = 0.0;
        if ($client instanceof Client) {
            $balance = ClientBalanceViewAccess::visibleDefaultBalanceValue(
                $client,
                auth('api')->user(),
                $companyId ?? ResolvedCompany::fromRequest(request())
            );
        }

        return [
            'id' => data_get($client, 'id'),
            'client_type' => data_get($client, 'client_type'),
            'first_name' => data_get($client, 'first_name'),
            'last_name' => data_get($client, 'last_name'),
            'patronymic' => data_get($client, 'patronymic'),
            'balance' => $balance,
            'is_supplier' => data_get($client, 'is_supplier'),
            'is_conflict' => data_get($client, 'is_conflict'),
            'position' => data_get($client, 'position'),
            'created_at' => self::formatDateTimeValue(data_get($client, 'created_at')),
            'updated_at' => self::formatDateTimeValue(data_get($client, 'updated_at')),
        ];
    }

    /**
     * @param  mixed  $value
     */
    public static function formatDateTimeValue($value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value !== null ? (string) $value : null;
    }
}
