<?php

use Illuminate\Support\Facades\Route;

// Простое перенаправление на фронтенд
Route::get('/', function () {
    return redirect('https://hassap.online');
});

Route::get('/dashboard', function () {
    return redirect('https://hassap.online');
});
