<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\Client;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;

class StoreClientRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'first_name'       => 'required|string',
            'is_conflict'      => 'sometimes|nullable|boolean',
            'is_supplier'      => 'sometimes|nullable|boolean',
            'last_name'        => 'nullable|string',
            'contact_person'   => 'nullable|string',
            'client_type'      => 'required|string|in:company,individual,employee,investor',
            'employee_id'      => 'nullable|exists:users,id',
            'address'          => 'nullable|string',
            'phones'           => 'required|array',
            'phones.*'         => 'string|distinct|min:6',
            'emails'           => 'sometimes|nullable',
            'emails.*'         => 'nullable|email|distinct',
            'note'             => 'nullable|string',
            'status'           => 'boolean',
            'discount'         => 'nullable|numeric|min:0',
            'discount_type'    => 'nullable|in:fixed,percent',
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $controller = new Controller();
            
            // Проверка дублирования employee_id
            $employeeId = $this->input('employee_id');
            if (!empty($employeeId)) {
                $companyId = $controller->getCurrentCompanyId();
                $query = Client::where('employee_id', $employeeId);

                if ($companyId) {
                    $query->where('company_id', $companyId);
                } else {
                    $query->whereNull('company_id');
                }

                if ($query->exists()) {
                    $validator->errors()->add('employee_id', 'Этот пользователь уже привязан к другому клиенту');
                }
            }

            // Проверка дублирования телефонов
            $phones = $this->input('phones', []);
            if (!empty($phones)) {
                $companyId = $controller->getCurrentCompanyId();
                foreach ($phones as $phone) {
                    $query = DB::table('clients_phones')
                        ->join('clients', 'clients_phones.client_id', '=', 'clients.id')
                        ->where('clients_phones.phone', $phone);

                    if ($companyId) {
                        $query->where('clients.company_id', $companyId);
                    } else {
                        $query->whereNull('clients.company_id');
                    }

                    if ($query->exists()) {
                        $validator->errors()->add('phones', "Телефон {$phone} уже используется другим клиентом в этой компании");
                        break;
                    }
                }
            }
        });
    }
}

