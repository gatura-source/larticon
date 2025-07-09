<?php

use App\Http\Controllers\IdenticonController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/avatar/{seed}', [IdenticonController::class, 'generate']);
Route::get('/avatar/{seed}/info', [IdenticonController::class, 'info']);
