<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;

class SetUserPassword extends Command
{
    protected $signature = 'user:set-password {user_id} {password}';
    protected $description = 'Установить пароль для пользователя';

    public function handle()
    {
        $userId = $this->argument('user_id');
        $password = $this->argument('password');

        $user = User::find($userId);

        if (!$user) {
            $this->error("Пользователь с ID {$userId} не найден");
            return 1;
        }

        $user->password = $password;
        $user->save();

        $this->info("✅ Пароль успешно установлен для пользователя: {$user->name} (ID: {$user->id}, Email: {$user->email})");

        return 0;
    }
}


