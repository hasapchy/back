<?php

namespace App\Repositories;

use App\Models\User;

class UsersRepository
{
    public function getAllUsers()
    {
        return User::all();
    }
}