<?php

namespace App\Http\Requests;

use App\Models\JournalEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreJournalEntryRequest extends FormRequest
{
    /**
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', JournalEntry::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'entry_date' => 'required|date',
            'description' => 'nullable|string|max:500',
            'lines' => 'required|array|min:2',
            'lines.*.account_code' => 'required|string|max:32',
            'lines.*.debit' => 'nullable|numeric|min:0|required_without:lines.*.credit',
            'lines.*.credit' => 'nullable|numeric|min:0|required_without:lines.*.debit',
            'lines.*.meta' => 'nullable|array',
        ];
    }

    /**
     * @param  Validator  $validator
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $lines = $this->input('lines', []);
            foreach ($lines as $index => $line) {
                $debit = (float) ($line['debit'] ?? 0);
                $credit = (float) ($line['credit'] ?? 0);
                if ($debit > 0 && $credit > 0) {
                    $validator->errors()->add("lines.{$index}.debit", 'Line cannot have both debit and credit.');
                }
                if ($debit <= 0 && $credit <= 0) {
                    $validator->errors()->add("lines.{$index}.debit", 'Line must have debit or credit.');
                }
            }
        });
    }
}
