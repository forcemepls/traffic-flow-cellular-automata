<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\NagelSchreckenbergService;

class NagelController extends Controller
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
            'vMax'       => 'required|integer',
        ]);

        $numberCars = $data['numberCars'];
        $roadLength = $data['roadLength'];
        $iterations = $data['iterations'];
        $vMax       = $data['vMax'];

        $service = new NagelSchreckenbergService();

        $positions = range(0, $roadLength - 1);
        shuffle($positions);
        $selectedPositions = array_slice($positions, 0, $numberCars);

        $machines = [];
        foreach ($selectedPositions as $i => $pos){
            $machines[] = [
                'id' => $i,
                'speed' => 0,
                'position' => $pos,
            ];
        }

        $history = $service->calculateStep($machines, $roadLength, $iterations, $vMax);

        return response()->json($history);
    }
}
