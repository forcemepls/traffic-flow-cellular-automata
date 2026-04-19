<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ExtendedNagelService;
use App\Services\NagelSchreckenbergService;
use App\Services\StatisticsService;

class SimulationController extends Controller
{
    public function indexBasic() {
        return view('simulation', [
            'mode' => 'basic',
            'title' => 'Модель Нагеля-Шрекенберга'
        ]);
    }

    public function indexExtended() {
        return view('simulation', [
            'mode' => 'extended',
            'title' => 'Расширенная модель'
        ]);
    }

    public function calculate(Request $request)
    {
        $data = $request->validate([
            'numberCars' => 'required|integer|min:1',
            'roadLength' => 'required|integer|min:4|max:200',
            'iterations' => 'required|integer|min:1',
            'vMax' => 'required|integer|min:1',
            'mode' => 'required|string',
        ]);

        $roadLength = $data['roadLength'];
        $vMax = $data['vMax'];

        // Выбор модели
        if ($data['mode'] === 'nagelschreckenberg') {
            $service = new NagelSchreckenbergService();
            $isTwoLanes = false;
        } else {
            $service = new ExtendedNagelService();
            $isTwoLanes = true;
        }

        // Расстановка машин
        $positions = range(0, $roadLength - 1);
        shuffle($positions);
        $selectedPositions = array_slice($positions, 0, $data['numberCars']);

        $machines = [];
        foreach ($selectedPositions as $i => $pos) {
            $machines[] = [
                'id' => $i,
                'speed' => 0,
                'position' => $pos,
                'lane' => $isTwoLanes ? rand(0, 1) : 0,
            ];
        }

        // Расчёт симуляции
        $history = $service->calculateStep($machines, $roadLength, $data['iterations'], $vMax);

        // Расчёт статистики
        $statisticsService = new StatisticsService();
        $statistics = $statisticsService->calculate($history, $roadLength, $vMax, $isTwoLanes);

        return response()->json([
            'history' => $history,
            'statistics' => $statistics,
        ]);
    }
}
