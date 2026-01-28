<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SimulationController;

// Route::get('/', function () {
//     return view('simulation');
// });

Route::get('/simulation_nagel', [SimulationController::class, 'index']);
Route::post('/simulation_nagel/calculate', [SimulationController::class, 'calculate']);

