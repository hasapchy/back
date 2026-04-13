<?php

namespace App\Http\Requests;

use App\Models\RecSchedule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRecurringTransactionRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        $schedule = RecSchedule::query()->find($this->route('id'));

        if (! $schedule) {
            return true;
        }

        return $this->user()->can('update', $schedule);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'template_id' => 'sometimes|exists:templates,id',
            'start_date' => 'sometimes|date',
            'recurrence_rule' => 'sometimes|array',
            'recurrence_rule.frequency' => 'required_with:recurrence_rule|string|in:daily,weekly,monthly,weekdays',
            'recurrence_rule.interval' => 'nullable|integer|min:1',
            'recurrence_rule.weekdays' => 'nullable|array',
            'recurrence_rule.weekdays.*' => 'integer|min:0|max:6',
            'recurrence_rule.month_day' => 'nullable|integer|min:1|max:31',
            'end_date' => 'nullable|date',
            'end_count' => 'nullable|integer|min:1',
            'is_active' => 'nullable|boolean',
        ];
    }
}
