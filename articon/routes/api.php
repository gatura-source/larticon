<?php

use App\Http\Controllers\ArticonController;
use Illuminate\Support\Facades\Route;

Route::get('/avatar', [ArticonController::class, 'generate']);

?>
