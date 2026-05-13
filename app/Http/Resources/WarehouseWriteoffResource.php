<?php

namespace App\Http\Resources;

use App\Enums\WhWriteoffReason;

class WarehouseWriteoffResource extends BaseDomainResource
{
    /**
     * @param  mixed  $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $data = parent::toArray($request);
        $data['reason'] = static::serializedReason($data['reason'] ?? null);

        return $data;
    }

    /**
     * @param  mixed  $reason
     */
    public static function serializedReason($reason): string
    {
        if ($reason instanceof \BackedEnum) {
            return $reason->value;
        }
        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        return WhWriteoffReason::Other->value;
    }
}
