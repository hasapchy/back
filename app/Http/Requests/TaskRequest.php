<?php

namespace App\Http\Requests;

use App\Models\Task;
use App\Rules\CompanyUserMembershipRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class TaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if ($this->isMethod('POST')) {
            return $this->user()->can('create', Task::class);
        }

        $task = Task::query()->find($this->route('id'));

        if (! $task) {
            return true;
        }

        return $this->user()->can('update', $task);
    }

        /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Преобразуем checklist из JSON строки в массив, если необходимо
        if ($this->has('checklist')) {
            $checklist = $this->input('checklist');

            // Если checklist - это массив с одним элементом-строкой (JSON)
            if (is_array($checklist) && count($checklist) === 1 && is_string($checklist[0])) {
                $decoded = json_decode($checklist[0], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $this->merge(['checklist' => $decoded]);
                    return;
                }
            }

            // Если checklist - это строка, пытаемся распарсить её как JSON
            if (is_string($checklist)) {
                $decoded = json_decode($checklist, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $this->merge(['checklist' => $decoded]);
                    return;
                } else {
                    // Если не удалось распарсить, устанавливаем пустой массив
                    $this->merge(['checklist' => []]);
                    return;
                }
            }

            // Если checklist уже массив объектов, оставляем как есть
            if (is_array($checklist)) {
                // Проверяем, что это массив объектов, а не массив строк
                $isValidArray = true;
                foreach ($checklist as $item) {
                    if (!is_array($item) && !is_object($item)) {
                        $isValidArray = false;
                        break;
                    }
                }
                if ($isValidArray) {
                    // Оставляем как есть
                    return;
                }
            }

            // Если checklist не массив и не null, устанавливаем пустой массив
            if (!is_array($checklist) && !is_null($checklist)) {
                $this->merge(['checklist' => []]);
                return;
            }

            // Если checklist null или пустой, устанавливаем пустой массив
            if (is_null($checklist) || empty($checklist)) {
                $this->merge(['checklist' => []]);
            }
        } else {
            // Если checklist не передан, устанавливаем пустой массив
            $this->merge(['checklist' => []]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'supervisor_id' => 'required|exists:users,id',
            'executor_id' => 'required|exists:users,id',
            'project_id' => 'nullable|exists:projects,id',
            'status_id' => 'nullable|exists:task_statuses,id',
            'priority' => 'nullable|in:low,normal,high',
            'complexity' => 'nullable|in:simple,normal,complex',
            'deadline' => 'nullable|date',
            'files' => 'nullable|array',
            'comments' => 'nullable|array',
            'checklist' => 'nullable|array',
            'observer_ids' => 'nullable|array',
            'observer_ids.*' => ['integer', 'exists:users,id', new CompanyUserMembershipRule()],
            'restrict_visibility' => 'nullable|boolean',
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['title'] = 'sometimes|required|string|max:255';
            $rules['supervisor_id'] = 'sometimes|required|exists:users,id';
            $rules['executor_id'] = 'sometimes|required|exists:users,id';
            $rules['status_id'] = 'sometimes|nullable|exists:task_statuses,id';
        }

        return $rules;
    }

    /**
     * @return void
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $observerIds = $this->input('observer_ids');
            if (! is_array($observerIds) || $observerIds === []) {
                return;
            }

            $supervisorId = (int) $this->input('supervisor_id');
            $executorId = (int) $this->input('executor_id');

            foreach ($observerIds as $observerId) {
                $id = (int) $observerId;
                if ($supervisorId > 0 && $id === $supervisorId) {
                    $validator->errors()->add('observer_ids', __('api.tasks.observer_cannot_be_supervisor'));
                }
                if ($executorId > 0 && $id === $executorId) {
                    $validator->errors()->add('observer_ids', __('api.tasks.observer_cannot_be_executor'));
                }
            }
        });
    }
}
