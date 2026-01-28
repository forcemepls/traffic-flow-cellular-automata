<?php

namespace App\Services;

class ExtendedNagelService extends NagelSchreckenbergService
{
    // поиск дистанции с учетом полосы
    private function getGapToCar($machine, $allMachines, $roadLength, $targetLane, $lookBack = false) {
        $minDist = $roadLength;

        foreach ($allMachines as $other) {
            if ($other['id'] === $machine['id']) continue;
            if ($other['lane'] !== $targetLane) continue;

            $d = $roadLength;

            if (!$lookBack) { // Вперед
                if ($other['position'] > $machine['position']) {
                    $d = $other['position'] - $machine['position'] - 1;
                } else {
                    $d = ($roadLength - $machine['position']) + $other['position'] - 1;
                }
            } else { // Назад
                if ($machine['position'] > $other['position']) {
                    $d = $machine['position'] - $other['position'] - 1;
                } else {
                    $d = ($roadLength - $other['position']) + $machine['position'] - 1;
                }
            }

            if ($d < $minDist) $minDist = $d;
        }
        return $minDist;
    }

    private function changeLanes(array $machines, int $roadLength, int $vMax): array
    {
        $snapshot = $machines; 
        
        foreach ($machines as &$car) {
            $myLane = $car['lane'];
            $v = $car['speed'];

            // Дистанция впереди в СВОЕЙ полосе
            $gapHere = $this->getGapToCar($car, $snapshot, $roadLength, $myLane);

            if ($myLane === 0) {
                // --- ЛОГИКА ДЛЯ ОСНОВНОЙ ПОЛОСЫ (0) ---
                // Хотим обогнать, если уперлись в машину спереди
                $blocked = $gapHere < ($v + 1);

                if ($blocked) {
                    // Проверяем полосу обгона (1)
                    $gapOtherForward = $this->getGapToCar($car, $snapshot, $roadLength, 1);
                    $gapOtherBack = $this->getGapToCar($car, $snapshot, $roadLength, 1, true);

                    // Условия обгона:
                    // 1. В соседней полосе места больше, чем здесь (есть смысл рыпаться)
                    $nice = $gapOtherForward > $gapHere;
                    // 2. Сзади на соседней полосе безопасно (не подрежем)
                    $safe = $gapOtherBack > $vMax;

                    if ($nice && $safe) {
                        $car['lane'] = 1; // Уходим на обгон
                    }
                }

            } else {
                // --- ЛОГИКА ДЛЯ ПОЛОСЫ ОБГОНА (1) ---
                // Пытаемся вернуться назад в полосу 0 (Keep Right rule)
                
                $gapRightForward = $this->getGapToCar($car, $snapshot, $roadLength, 0);
                $gapRightBack = $this->getGapToCar($car, $snapshot, $roadLength, 0, true);

                // Условия возврата:
                // 1. Справа безопасно сзади (не подрежем того, кого обогнали)
                $safeBack = $gapRightBack > $vMax;
                // 2. Справа достаточно места спереди (не прыгнем сразу в бампер другой машине)
                $safeForward = $gapRightForward >= ($v + 1);

                if ($safeBack && $safeForward) {
                    $car['lane'] = 0; // Возвращаемся в ряд
                }
            }
        }
        return $machines;
    }

    // ПЕРЕОПРЕДЕЛЯЕМ ГЛАВНЫЙ МЕТОД
    public function calculateStep(array $initialState, int $roadLength, int $iterations, int $vMax): array
    {
        $history = [];
        $currentMachines = $initialState;
        $history[] = $currentMachines;

        for ($t = 0; $t < $iterations; $t++) {
            // 1. Смена полосы
            $currentMachines = $this->changeLanes($currentMachines, $roadLength, $vMax);

            // 2. Расчет скорости
            $nextMachinesState = [];
            $snapshot = $currentMachines;

            foreach ($currentMachines as $machine) {
                $v = $machine['speed'];
                // Gap ищем только в СВОЕЙ полосе
                $gap = $this->getGapToCar($machine, $snapshot, $roadLength, $machine['lane']);

                // Методы родителя
                $v = $this->speedup($v, $vMax);
                $v = $this->slowdown($v, $gap);
                $v = $this->random($v, 0.3);

                $nextMachinesState[] = [
                    'id' => $machine['id'],
                    'lane' => $machine['lane'],
                    'speed' => $v,
                    'position' => $machine['position']
                ];
            }

            // 3. Движение
            foreach ($nextMachinesState as &$m) {
                $m['position'] = $this->move($m['position'], $m['speed'], $roadLength);
            }
            unset($m);

            $history[] = $nextMachinesState;
            $currentMachines = $nextMachinesState;
        }
        return $history;
    }
}