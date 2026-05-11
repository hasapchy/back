<?php

namespace App\Http\Resources;

use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaveReferenceResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $leaveType = data_get($this->resource, 'leave_type') ?? data_get($this->resource, 'leaveType');
        $user = data_get($this->resource, 'user');

        return [
            'comment' => data_get($this->resource, 'comment'),
            'company_id' => data_get($this->resource, 'company_id'),
            'created_at' => $this->formatDateTimeValue(data_get($this->resource, 'created_at')),
            'date_from' => $this->formatDateTimeValue(data_get($this->resource, 'date_from')),
            'date_to' => $this->formatDateTimeValue(data_get($this->resource, 'date_to')),
            'id' => data_get($this->resource, 'id'),
            'leave_type' => $leaveType ? [
                'id' => data_get($leaveType, 'id'),
                'name' => data_get($leaveType, 'name'),
                'color' => data_get($leaveType, 'color'),
                'is_penalty' => data_get($leaveType, 'is_penalty'),
                'created_at' => $this->formatDateTimeValue(data_get($leaveType, 'created_at')),
                'updated_at' => $this->formatDateTimeValue(data_get($leaveType, 'updated_at')),
            ] : null,
            'leave_type_id' => data_get($this->resource, 'leave_type_id'),
            'updated_at' => $this->formatDateTimeValue(data_get($this->resource, 'updated_at')),
            'user' => $user ? [
                'id' => data_get($user, 'id'),
                'name' => data_get($user, 'name'),
                'surname' => data_get($user, 'surname'),
                'email' => data_get($user, 'email'),
            ] : null,
            'user_id' => data_get($this->resource, 'user_id'),
        ];
    }

    /**
     * @param  mixed  $value
     */
    private function formatDateTimeValue($value): ?string
    {
        if ($value instanceof CarbonInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value !== null ? (string) $value : null;
    }
}
