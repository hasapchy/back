<?php

namespace App\Http\Requests;

use App\Models\News;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNewsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $news = News::query()->find($this->route('id'));

        if (! $news) {
            return true;
        }

        return $this->user()->can('update', $news);
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|required|string|max:255',
            'content' => 'sometimes|required|string|max:50000', // Ограничение размера контента (примерно 50 КБ текста)
        ];
    }
}
