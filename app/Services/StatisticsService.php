<?php

namespace App\Services;

class StatisticsService
{
    /**
     * Рассчитать всю статистику по истории симуляции
     */
    public function calculate(array $history, int $roadLength, int $vMax, bool $isTwoLanes = false): array
    {
        $totalSteps = count($history);
        $numCars = count($history[0] ?? []);

        if ($numCars === 0 || $totalSteps < 2) {
            return $this->emptyStatistics();
        }

        // Плотность (константа для данной симуляции)
        $density = $isTwoLanes
            ? $numCars / (2 * $roadLength)
            : $numCars / $roadLength;

        // Массивы для пошаговой статистики
        $perStep = [
            'speed' => [],
            'congestionRate' => [],
            'brakingIndex' => [],
            'avgGap' => [],
            'laneChangeRate' => [],
            'flow' => [],
        ];

        // Для отслеживания времени в пути (полный круг)
        $travelTracking = [];
        foreach ($history[0] as $car) {
            $travelTracking[$car['id']] = [
                'startPosition' => $car['position'],
                'totalDistance' => 0,
                'completedLap' => false,
                'lapTime' => null,
            ];
        }

        // Для эффективности обгонов
        $speedBeforeOvertake = [];
        $speedAfterOvertake = [];

        $previousStep = null;

        // Проходим по каждому шагу
        foreach ($history as $stepIndex => $step) {

            // --- 1. Средняя скорость ---
            $speeds = array_column($step, 'speed');
            $avgSpeed = array_sum($speeds) / count($speeds);
            $perStep['speed'][] = round($avgSpeed, 2);

            // --- 2. Коэффициент заторов (доля машин с v=0) ---
            $stoppedCars = count(array_filter($speeds, fn($v) => $v === 0));
            $congestionRate = $stoppedCars / $numCars;
            $perStep['congestionRate'][] = round($congestionRate, 3);

            // --- 3. Индекс замедлений ---
            $brakingCount = 0;
            if ($previousStep !== null) {
                $prevSpeedMap = [];
                foreach ($previousStep as $car) {
                    $prevSpeedMap[$car['id']] = $car['speed'];
                }
                foreach ($step as $car) {
                    if (isset($prevSpeedMap[$car['id']]) && $car['speed'] < $prevSpeedMap[$car['id']]) {
                        $brakingCount++;
                    }
                }
            }
            $brakingIndex = $previousStep !== null ? $brakingCount / $numCars : 0;
            $perStep['brakingIndex'][] = round($brakingIndex, 3);

            // --- 4. Средний Gap ---
            $avgGap = $this->calculateAverageGap($step, $roadLength, $isTwoLanes);
            $perStep['avgGap'][] = round($avgGap, 2);

            // --- 5. Интенсивность перестроений ---
            $laneChanges = 0;
            if ($previousStep !== null && $isTwoLanes) {
                $prevLaneMap = [];
                foreach ($previousStep as $car) {
                    $prevLaneMap[$car['id']] = $car['lane'] ?? 0;
                }
                foreach ($step as $car) {
                    $currentLane = $car['lane'] ?? 0;
                    if (isset($prevLaneMap[$car['id']]) && $currentLane !== $prevLaneMap[$car['id']]) {
                        $laneChanges++;

                        // Для эффективности обгонов
                        $carId = $car['id'];
                        $prevSpeed = $prevSpeedMap[$carId] ?? 0;

                        // Если перестроился в полосу обгона (0 -> 1)
                        if ($prevLaneMap[$carId] === 0 && $currentLane === 1) {
                            $speedBeforeOvertake[$carId] = $prevSpeed;
                        }
                        // Если вернулся из полосы обгона (1 -> 0)
                        elseif ($prevLaneMap[$carId] === 1 && $currentLane === 0) {
                            if (isset($speedBeforeOvertake[$carId])) {
                                $speedAfterOvertake[$carId] = $car['speed'];
                            }
                        }
                    }
                }
            }
            $laneChangeRate = $numCars > 0 ? $laneChanges / $numCars : 0;
            $perStep['laneChangeRate'][] = round($laneChangeRate, 3);

            // --- 6. Поток (Flow) ---
            $flow = $density * $avgSpeed;
            $perStep['flow'][] = round($flow, 3);

            // --- 7. Время в пути (отслеживание) ---
            foreach ($step as $car) {
                $id = $car['id'];
                if (!$travelTracking[$id]['completedLap']) {
                    $travelTracking[$id]['totalDistance'] += $car['speed'];

                    if ($travelTracking[$id]['totalDistance'] >= $roadLength) {
                        $travelTracking[$id]['completedLap'] = true;
                        $travelTracking[$id]['lapTime'] = $stepIndex;
                    }
                }
            }

            $previousStep = $step;
        }

        // --- Итоговые расчёты ---

        // Суммарная статистика
        $summary = [
            'avgSpeed' => round(array_sum($perStep['speed']) / count($perStep['speed']), 2),
            'avgCongestionRate' => round(array_sum($perStep['congestionRate']) / count($perStep['congestionRate']), 3),
            'avgBrakingIndex' => round(array_sum($perStep['brakingIndex']) / count($perStep['brakingIndex']), 3),
            'avgGap' => round(array_sum($perStep['avgGap']) / count($perStep['avgGap']), 2),
            'totalLaneChanges' => $isTwoLanes ? array_sum(array_map(fn($r) => (int)round($r * $numCars), $perStep['laneChangeRate'])) : 0,
            'avgFlow' => round(array_sum($perStep['flow']) / count($perStep['flow']), 3),
            'density' => round($density, 3),
            'vMax' => $vMax,
        ];

        // Время в пути
        $travelTimes = [];
        $idealTime = $roadLength / $vMax;
        foreach ($travelTracking as $id => $data) {
            $travelTimes[] = [
                'id' => $id,
                'lapTime' => $data['lapTime'],
                'completed' => $data['completedLap'],
                'delay' => $data['completedLap'] ? round($data['lapTime'] - $idealTime, 1) : null,
            ];
        }

        // Эффективность обгонов
        $overtakeEfficiency = 0;
        if (count($speedAfterOvertake) > 0) {
            $totalEfficiency = 0;
            $count = 0;
            foreach ($speedAfterOvertake as $id => $afterSpeed) {
                $beforeSpeed = $speedBeforeOvertake[$id] ?? 0;
                if ($beforeSpeed > 0) {
                    $totalEfficiency += ($afterSpeed - $beforeSpeed) / $beforeSpeed;
                    $count++;
                } elseif ($afterSpeed > 0) {
                    $totalEfficiency += 1; // Было 0, стало > 0 — 100% улучшение
                    $count++;
                }
            }
            $overtakeEfficiency = $count > 0 ? round($totalEfficiency / $count, 3) : 0;
        }

        // Фундаментальная диаграмма (одна точка)
        $fundamentalDiagram = [
            'density' => round($density, 3),
            'flow' => $summary['avgFlow'],
            'speed' => $summary['avgSpeed'],
        ];

        return [
            'summary' => $summary,
            'perStep' => $perStep,
            'travelTimes' => $travelTimes,
            'overtakeEfficiency' => $overtakeEfficiency,
            'fundamentalDiagram' => $fundamentalDiagram,
            'meta' => [
                'totalSteps' => $totalSteps,
                'numCars' => $numCars,
                'roadLength' => $roadLength,
                'isTwoLanes' => $isTwoLanes,
            ],
        ];
    }

    /**
     * Расчёт среднего gap для всех машин
     */
    private function calculateAverageGap(array $step, int $roadLength, bool $isTwoLanes): float
    {
        if (count($step) <= 1) {
            return $roadLength;
        }

        $gaps = [];

        if ($isTwoLanes) {
            // Разделяем по полосам
            $lanes = [[], []];
            foreach ($step as $car) {
                $lane = $car['lane'] ?? 0;
                $lanes[$lane][] = $car;
            }

            foreach ($lanes as $laneCars) {
                if (count($laneCars) > 1) {
                    usort($laneCars, fn($a, $b) => $a['position'] <=> $b['position']);

                    for ($i = 0; $i < count($laneCars); $i++) {
                        $current = $laneCars[$i];
                        $next = $laneCars[($i + 1) % count($laneCars)];

                        if ($i === count($laneCars) - 1) {
                            $gap = ($roadLength - $current['position']) + $next['position'] - 1;
                        } else {
                            $gap = $next['position'] - $current['position'] - 1;
                        }
                        $gaps[] = max(0, $gap);
                    }
                }
            }
        } else {
            // Однополосный случай
            $sorted = $step;
            usort($sorted, fn($a, $b) => $a['position'] <=> $b['position']);

            for ($i = 0; $i < count($sorted); $i++) {
                $current = $sorted[$i];
                $next = $sorted[($i + 1) % count($sorted)];

                if ($i === count($sorted) - 1) {
                    $gap = ($roadLength - $current['position']) + $next['position'] - 1;
                } else {
                    $gap = $next['position'] - $current['position'] - 1;
                }
                $gaps[] = max(0, $gap);
            }
        }

        return count($gaps) > 0 ? array_sum($gaps) / count($gaps) : 0;
    }

    /**
     * Пустая статистика для edge cases
     */
    private function emptyStatistics(): array
    {
        return [
            'summary' => [
                'avgSpeed' => 0,
                'avgCongestionRate' => 0,
                'avgBrakingIndex' => 0,
                'avgGap' => 0,
                'totalLaneChanges' => 0,
                'avgFlow' => 0,
                'density' => 0,
                'vMax' => 0,
            ],
            'perStep' => [
                'speed' => [],
                'congestionRate' => [],
                'brakingIndex' => [],
                'avgGap' => [],
                'laneChangeRate' => [],
                'flow' => [],
            ],
            'travelTimes' => [],
            'overtakeEfficiency' => 0,
            'fundamentalDiagram' => ['density' => 0, 'flow' => 0, 'speed' => 0],
            'meta' => ['totalSteps' => 0, 'numCars' => 0, 'roadLength' => 0, 'isTwoLanes' => false],
        ];
    }
}
