<?php

namespace App\Http\Controllers;

use App\Services\ExtendedNagelService;
use Illuminate\Http\Request;
use App\Services\NagelSchreckenbergService;

class SimulationController extends Controller
{

    public function index(){
        return view('simulation');
    }

    public function calculate(Request $request)
    {
        $data = $request->validate([
            'numberCars' => 'required|integer',
            'roadLength' => 'required|integer|min:4|max:200',
            'iterations' => 'required|integer',
            'vMax' => 'required|integer',
            'mode' => 'required|string',
        ]);

        $roadLength = $data['roadLength'];

        // выбор модели
        if ($data['mode'] == 'nagelschreckenberg'){
            $service = new NagelSchreckenbergService();
            $isTwoLanes = false;
        } else{
            $service = new ExtendedNagelService();
            $isTwoLanes = true;
        }

        // расстановка машин через шафл
        $positions = range(0, $roadLength - 1);
        shuffle($positions);
        $selectedPositions = array_slice($positions, 0, $data['numberCars']);

        $machines = [];
        foreach ($selectedPositions as $i => $pos){
            $machines[] = [
                'id' => $i,
                'speed' => 0,
                'position' => $pos,
                //'lane' => $isTwoLanes ? rand(0, 1) : 0,
                'lane' => 0,
            ];
        }

        $history = $service->calculateStep($machines, $roadLength, $data['iterations'], $data['vMax']);

        return response()->json($history);
    }
}
