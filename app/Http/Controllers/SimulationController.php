<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ExtendedNagelService;
use App\Services\NagelSchreckenbergService;
use App\Services\StatisticsService;
use App\Services\TJunctionService;
use App\Services\TJunctionStatisticsService;

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

    public function indexTJunction()
    {
        return view('simulation_t_junction', [
            'title' => 'T-образный перекрёсток'
        ]);
    }

    public function calculate(Request $request)
    {
        // Базовая валидация — общие поля для всех режимов
        $base = $request->validate([
            'mode'       => 'required|string|in:nagelschreckenberg,extendednagelschreckenberg,tjunction',
            'roadLength' => 'required|integer|min:4|max:200',
            'iterations' => 'required|integer|min:1|max:5000',
            'vMax'       => 'required|integer|min:1|max:10',
            'p'          => 'nullable|numeric|min:0|max:1',
        ]);

        $roadLength = $base['roadLength'];
        $vMax       = $base['vMax'];
        $p          = $base['p'] ?? 0.3;

        // -------------------------------------------------------
        // Режим T-образного перекрёстка
        // -------------------------------------------------------
        if ($base['mode'] === 'tjunction') {

            $data = $request->validate([
                'tPhaseMain' => 'required|integer|min:5|max:300',
                'tPhaseSec'  => 'required|integer|min:5|max:300',
                'lambdaW'    => 'required|numeric|min:0|max:120',
                'lambdaE'    => 'required|numeric|min:0|max:120',
                'lambdaS'    => 'required|numeric|min:0|max:120',
            ]);

            $service = new TJunctionService();
            $history = $service->calculateStep(
                $roadLength,
                $base['iterations'],
                $vMax,
                $p,
                $data['tPhaseMain'],
                $data['tPhaseSec'],
                $data['lambdaW'],
                $data['lambdaE'],
                $data['lambdaS']
            );

            $statistics = (new TJunctionStatisticsService())->calculate(
                $history,
                $roadLength,
                $vMax,
                [
                    'main' => $data['tPhaseMain'],
                    'sec'  => $data['tPhaseSec'],
                ]
            );

            return response()->json([
                'history'    => $history,
                'statistics' => $statistics,
            ]);
        }

        // -------------------------------------------------------
        // Кольцевые модели — общие параметры
        // -------------------------------------------------------
        $ringData = $request->validate([
            'numberCars' => 'required|integer|min:1',
            'pChange'    => 'nullable|numeric|min:0|max:1',
        ]);

        $pChange    = $ringData['pChange'] ?? 1.0;
        $isTwoLanes = $base['mode'] === 'extendednagelschreckenberg';
        $laneCount  = $isTwoLanes ? 2 : 1;

        if ($ringData['numberCars'] > $roadLength * $laneCount) {
            return response()->json([
                'message' => 'Слишком много машин для такой дороги.',
                'errors'  => [
                    'numberCars' => [
                        'Максимум ' . ($roadLength * $laneCount) . ' машин для этой конфигурации.'
                    ],
                ],
            ], 422);
        }

        $machines = $this->placeCars($ringData['numberCars'], $roadLength, $laneCount);

        if ($isTwoLanes) {
            $service = new ExtendedNagelService();
            $history = $service->calculateStep($machines, $roadLength, $base['iterations'], $vMax, $p, $pChange);
        } else {
            $service = new NagelSchreckenbergService();
            $history = $service->calculateStep($machines, $roadLength, $base['iterations'], $vMax, $p);
        }

        $statistics = (new StatisticsService())
            ->calculate($history, $roadLength, $vMax, $isTwoLanes);

        return response()->json([
            'history'    => $history,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Расстановка машин без коллизий: для двух полос — раздельно по каждой.
     */
    private function placeCars(int $numberCars, int $roadLength, int $laneCount): array
    {
        // Собираем все доступные (lane, position) и берём случайное подмножество
        $slots = [];
        for ($lane = 0; $lane < $laneCount; $lane++) {
            for ($pos = 0; $pos < $roadLength; $pos++) {
                $slots[] = ['lane' => $lane, 'pos' => $pos];
            }
        }
        shuffle($slots);
        $chosen = array_slice($slots, 0, $numberCars);

        $machines = [];
        foreach ($chosen as $i => $slot) {
            $machines[] = [
                'id'       => $i,
                'speed'    => 0,
                'position' => $slot['pos'],
                'lane'     => $slot['lane'],
            ];
        }
        return $machines;
    }
}
