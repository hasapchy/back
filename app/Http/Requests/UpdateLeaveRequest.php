<?php

namespace App\Http\Requests;

use App\Models\Leave;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLeaveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $leave = Leave::query()->find($this->route('id'));

        if (! $leave) {
            return true;
        }

        return $this->user()->can('update', $leave);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'leave_type_id' => 'nullable|integer|exists:leave_types,id',
            'user_id' => 'nullable|integer|exists:users,id',
            'comment' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
        ];

        if ($this->has('date_from') && $this->has('date_to')) {
            $rules['date_to'] .= '|after_or_equal:date_from';
        }

        return $rules;
    }
}
