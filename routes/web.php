<?php

use Illuminate\Support\Facades\Route;

// Простое перенаправление на фронтенд
Route::get('/', function () {
    return redirect('https://app.ltm.studio');
});

Route::get('/dashboard', function () {
    return redirect('https://app.ltm.studio');
});
