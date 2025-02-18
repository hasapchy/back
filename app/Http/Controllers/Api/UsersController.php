<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\UsersRepository;
use Illuminate\Http\JsonResponse;


class UsersController extends Controller
{
    protected $usersRepository;

    public function __construct(UsersRepository $usersRepository)
    {
        $this->usersRepository = $usersRepository;
    }

    public function getAllUsers(): JsonResponse
    {
        $users = $this->usersRepository->getAllUsers();
        return response()->json($users);
    }
}