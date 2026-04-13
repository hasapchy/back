<?php

namespace App\Rules;

use App\Models\Project;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ProjectAccessRule implements ValidationRule
{
    public function __construct(
        protected $user = null
    ) {
        $this->user = $user ?? auth('api')->user();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value) {
            return;
        }

        if (! $this->user) {
            $fail('Поле :attribute некорректно. Пользователь не авторизован.');

            return;
        }

        $project = Project::find($value);

        if (! $project) {
            return;
        }

        if (! $this->user->can('view', $project)) {
            $fail('У вас нет доступа к этому проекту.');
        }
    }
}
