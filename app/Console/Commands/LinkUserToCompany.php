<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Company;

class LinkUserToCompany extends Command
{
    protected $signature = 'user:link-company {user_id} {company_id}';
    protected $description = 'Связать пользователя с компанией';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $companyId = $this->argument('company_id');
        
        $user = User::find($userId);
        if (!$user) {
            $this->error("Пользователь с ID {$userId} не найден");
            return;
        }
        
        $company = Company::find($companyId);
        if (!$company) {
            $this->error("Компания с ID {$companyId} не найдена");
            return;
        }
        
        // Проверяем, есть ли уже связь
        if ($user->companies()->where('company_id', $companyId)->exists()) {
            $this->info("Пользователь {$user->email} уже связан с компанией {$company->name}");
            return;
        }
        
        // Создаем связь
        $user->companies()->attach($companyId);
        
        $this->info("✅ Пользователь {$user->email} успешно связан с компанией {$company->name}");
    }
}
