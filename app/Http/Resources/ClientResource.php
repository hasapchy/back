<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class ClientResource extends BaseResource
{
    /**
     * Преобразовать ресурс в массив
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $resource = $this->resource;
        $isModel = $resource instanceof \Illuminate\Database\Eloquent\Model;
        $phones = $this->getResourceValue('phones', []);
        $emails = $this->getResourceValue('emails', []);

        return [
            'id' => $this->getResourceValue('id'),
            'user_id' => $this->getResourceValue('user_id'),
            'company_id' => $this->getResourceValue('company_id'),
            'employee_id' => $this->getResourceValue('employee_id'),
            'client_type' => $this->getResourceValue('client_type'),
            'is_supplier' => $this->toBoolean($this->getResourceValue('is_supplier')),
            'is_conflict' => $this->toBoolean($this->getResourceValue('is_conflict')),
            'first_name' => $this->getResourceValue('first_name'),
            'last_name' => $this->getResourceValue('last_name'),
            'contact_person' => $this->getResourceValue('contact_person'),
            'address' => $this->getResourceValue('address'),
            'note' => $this->getResourceValue('note'),
            'discount_type' => $this->getResourceValue('discount_type'),
            'discount' => $this->formatCurrency($this->getResourceValue('discount')),
            'status' => $this->toBoolean($this->getResourceValue('status')),
            'sort' => $this->getResourceValue('sort'),
            'balance' => $this->formatCurrency($this->getResourceValue('balance')),
            'phones' => $isModel
                ? $this->whenLoaded('phones', function () {
                    return $this->phones->map(function ($phone) {
                        return [
                            'id' => $phone->id,
                            'phone' => $phone->phone,
                        ];
                    });
                })
                : collect($phones)->map(function ($phone) {
                    return [
                        'id' => $phone['id'] ?? null,
                        'phone' => $phone['phone'] ?? null,
                    ];
                }),
            'emails' => $isModel
                ? $this->whenLoaded('emails', function () {
                    return $this->emails->map(function ($email) {
                        return [
                            'id' => $email->id,
                            'email' => $email->email,
                        ];
                    });
                })
                : collect($emails)->map(function ($email) {
                    return [
                        'id' => $email['id'] ?? null,
                        'email' => $email['email'] ?? null,
                    ];
                }),
            'user' => $isModel ? new UserResource($this->whenLoaded('user')) : null,
            'employee' => $isModel ? new UserResource($this->whenLoaded('employee')) : null,
            'created_at' => $this->formatDateTime($this->getResourceValue('created_at')),
            'updated_at' => $this->formatDateTime($this->getResourceValue('updated_at')),
        ];
    }
}

