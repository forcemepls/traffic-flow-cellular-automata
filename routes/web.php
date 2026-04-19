<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SimulationController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/simulation_nagel', [SimulationController::class, 'indexBasic']);
Route::get('/extended_simulation_nagel', [SimulationController::class, 'indexExtended']);

Route::post('/api/calculate', [SimulationController::class, 'calculate']);