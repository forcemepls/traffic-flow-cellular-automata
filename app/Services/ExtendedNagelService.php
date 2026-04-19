<?php

namespace App\Services;

class ExtendedNagelService extends NagelSchreckenbergService
{
    /**
     * Поиск дистанции (gap) до ближайшей машины в целевой полосе.
     * Возвращает количество ПУСТЫХ клеток.
     */
    private function getGapToCar($machine, $allMachines, $roadLength, $targetLane, $lookBack = false) {
        $minGap = $roadLength; // Начинаем с макс возможной дистанции

        foreach ($allMachines as $other) {
            // Пропускаем себя и машины в других полосах
            if ($other['id'] === $machine['id']) continue;
            if ($other['lane'] !== $targetLane) continue;

            // Расчет "сырой" дистанции в клетках
            if (!$lookBack) {
                // Дистанция ВПЕРЕД от нас до соседа
                // Формула (other - self + L) % L дает расстояние по кругу всегда вперед
                $dist = ($other['position'] - $machine['position'] + $roadLength) % $roadLength;
            } else {
                // Дистанция НАЗАД от нас до соседа (или от соседа до нас вперед)
                // Формула (self - other + L) % L
                $dist = ($machine['position'] - $other['position'] + $roadLength) % $roadLength;
            }

            // ВАЖНОЕ ИСПРАВЛЕНИЕ:
            // Если dist == 0, значит машины в одной клетке.
            // Gap должен быть -1 (авария/занято), чтобы запретить движение.
            if ($dist === 0) {
                return -1; 
            }

            // Количество пустых клеток = дистанция - 1
            // (если дистанция 1, то пустых клеток 0)
            $gap = $dist - 1;

            if ($gap < $minGap) {
                $minGap = $gap;
            }
        }
        
        return $minGap;
    }

    private function changeLanes(array $machines, int $roadLength, int $vMax): array
    {
        // Используем копию для синхронного обновления
        $snapshot = $machines; 
        
        foreach ($machines as &$car) {
            $myLane = $car['lane'];
            $v = $car['speed'];

            // 1. Считаем GAP в своей полосе (впереди)
            $gapHere = $this->getGapToCar($car, $snapshot, $roadLength, $myLane);

            if ($myLane === 0) {
                // --- (0) ОСНОВНАЯ ПОЛОСА -> ХОТИМ В ОБГОН (1) ---
                
                // Мотивация: уперлись в машину (gap < v + 1)
                $blocked = $gapHere < ($v + 1);

                if ($blocked) {
                    // Проверяем полосу обгона
                    $gapOtherForward = $this->getGapToCar($car, $snapshot, $roadLength, 1);
                    $gapOtherBack = $this->getGapToCar($car, $snapshot, $roadLength, 1, true);

                    // Условия:
                    // 1. Там свободнее (места больше)
                    $nice = $gapOtherForward > $gapHere;
                    
                    // 2. Безопасно сзади (Lgap_back >= v_max_other). 
                    // Обычно берут vMax, чтобы быстрый "гонщик" не влетел.
                    $safeBack = $gapOtherBack >= $vMax;

                    // 3. Безопасно спереди (не прыгаем в занятую клетку)
                    // Gap должен быть >= 0 (то есть dist >= 1)
                    $safeForward = $gapOtherForward >= 0;

                    if ($nice && $safeBack && $safeForward) {
                        $car['lane'] = 1;
                    }
                }

            } else {
                // --- (1) ПОЛОСА ОБГОНА -> ХОТИМ ОБРАТНО (0) ---
                
                $gapRightForward = $this->getGapToCar($car, $snapshot, $roadLength, 0);
                $gapRightBack = $this->getGapToCar($car, $snapshot, $roadLength, 0, true);

                // Условия возврата:
                // 1. Безопасно сзади
                $safeBack = $gapRightBack >= $vMax;
                
                // 2. Безопасно спереди (достаточно места для текущей скорости)
                // В оригинальной STCA правилах: мы возвращаемся, если не придется сразу тормозить
                // То есть gap >= v
                $safeForward = $gapRightForward >= $v; 

                if ($safeBack && $safeForward) {
                    $car['lane'] = 0; 
                }
            }
        }
        return $machines;
    }

    public function calculateStep(array $initialState, int $roadLength, int $iterations, int $vMax): array
    {
        $history = [];
        $currentMachines = $initialState;
        $history[] = $currentMachines;

        for ($t = 0; $t < $iterations; $t++) {
            // 1. Смена полосы
            $currentMachines = $this->changeLanes($currentMachines, $roadLength, $vMax);

            // 2. Расчет скорости и движения
            $nextMachinesState = [];
            $snapshot = $currentMachines; // Снимок уже с новыми полосами

            foreach ($currentMachines as $machine) {
                $v = $machine['speed'];
                
                // Gap в (возможно новой) полосе
                $gap = $this->getGapToCar($machine, $snapshot, $roadLength, $machine['lane']);

                // Стандартная физика
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

            // 3. Физическое перемещение
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