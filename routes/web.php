<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\NagelController;

// Route::get('/', function () {
//     return view('simulation');
// });

Route::get('/simulation_nagel', [NagelController::class, 'index']);

Route::post('simulation_nagel/calculate', [NagelController::class, 'calculate']);