<?php

namespace App\Http\Requests;

use App\Models\RecSchedule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRecurringTransactionRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', RecSchedule::class);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        return [
            'template_id' => 'required|exists:templates,id',
            'start_date' => 'required|date',
            'recurrence_rule' => 'required|array',
            'recurrence_rule.frequency' => 'required|string|in:daily,weekly,monthly,weekdays',
            'recurrence_rule.interval' => 'nullable|integer|min:1',
            'recurrence_rule.weekdays' => 'nullable|array',
            'recurrence_rule.weekdays.*' => 'integer|min:0|max:6',
            'recurrence_rule.month_day' => 'nullable|integer|min:1|max:31',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'end_count' => 'nullable|integer|min:1',
        ];
    }
}
