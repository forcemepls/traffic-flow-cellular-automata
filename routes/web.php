<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NagelController;

Route::get('/', function () {
    return view('simulation');
});

Route::get('/nagel', [NagelController::class, 'main']);