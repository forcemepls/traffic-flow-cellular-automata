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
            'iterations' => 'required|integer|min:1|max:5000',
            'vMax'       => 'required|integer|min:1|max:10',
            'mode'       => 'required|string|in:nagelschreckenberg,extendednagelschreckenberg',
            'p'          => 'nullable|numeric|min:0|max:1',
            'pChange'    => 'nullable|numeric|min:0|max:1',
        ]);

        $roadLength = $data['roadLength'];
        $vMax       = $data['vMax'];
        $p          = $data['p']       ?? 0.3;
        $pChange    = $data['pChange'] ?? 1.0;

        $isTwoLanes = $data['mode'] === 'extendednagelschreckenberg';
        $laneCount  = $isTwoLanes ? 2 : 1;

        // Проверка влезут ли машины (более дружественно к пользователю, чем просто min)
        if ($data['numberCars'] > $roadLength * $laneCount) {
            return response()->json([
                'message' => 'Слишком много машин для такой дороги.',
                'errors'  => [
                    'numberCars' => ['Максимум ' . ($roadLength * $laneCount) . ' машин для этой конфигурации.']
                ],
            ], 422);
        }

        // Расстановка
        $machines = $this->placeCars($data['numberCars'], $roadLength, $laneCount);

        // Расчёт
        if ($isTwoLanes) {
            $service = new ExtendedNagelService();
            $history = $service->calculateStep($machines, $roadLength, $data['iterations'], $vMax, $p, $pChange);
        } else {
            $service = new NagelSchreckenbergService();
            $history = $service->calculateStep($machines, $roadLength, $data['iterations'], $vMax, $p);
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
