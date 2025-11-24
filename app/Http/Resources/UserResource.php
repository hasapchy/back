<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class UserResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->toBoolean($this->is_active),
            'photo' => $this->photo,
            'photo_url' => $this->assetUrl($this->photo),
            'position' => $this->position,
            'hire_date' => $this->formatDate($this->hire_date),
            'birthday' => $this->formatDate($this->birthday),
            'last_login_at' => $this->formatDateTime($this->last_login_at),
            'created_at' => $this->formatDateTime($this->created_at),
            'updated_at' => $this->formatDateTime($this->updated_at),
        ];
    }
}

