<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(config('app.url'));
});

Route::get('/dashboard', function () {
    return redirect(config('app.url'));
});
