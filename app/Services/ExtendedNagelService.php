<?php

namespace App\Services;

class ExtendedNagelService extends NagelSchreckenbergService
{
    /**
     * Построить индекс [lane][position] => car для O(1) поиска.
     */
    private function buildGrid(array $machines, int $roadLength): array
    {
        $grid = [0 => [], 1 => []];
        foreach ($machines as $car) {
            $grid[$car['lane']][$car['position']] = $car;
        }
        return $grid;
    }

    /**
     * Gap вперёд в целевой полосе, считая от позиции $pos.
     * Возвращает число ПУСТЫХ клеток до ближайшей машины впереди.
     * Если клетка под собой занята (одновременно существуют две машины в одной клетке) — вернёт 0.
     */
    private function gapForward(array $grid, int $targetLane, int $pos, int $roadLength): int
    {
        for ($i = 1; $i <= $roadLength; $i++) {
            $cell = ($pos + $i) % $roadLength;
            if (isset($grid[$targetLane][$cell])) {
                return $i - 1;
            }
        }
        return $roadLength; // полоса пуста
    }

    /**
     * Gap назад: расстояние от ближайшей машины сзади до позиции $pos (в пустых клетках).
     */
    private function gapBackward(array $grid, int $targetLane, int $pos, int $roadLength): int
    {
        for ($i = 1; $i <= $roadLength; $i++) {
            $cell = ($pos - $i + $roadLength) % $roadLength;
            if (isset($grid[$targetLane][$cell])) {
                return $i - 1;
            }
        }
        return $roadLength;
    }

    /**
     * Этап 1: сбор намерений о смене полосы.
     * Возвращает массив [carId => targetLane] только для тех, кто хочет перестроиться.
     */
    private function collectLaneChangeIntents(
        array $machines,
        array $grid,
        int $roadLength,
        int $vMax,
        float $pChange
    ): array {
        $intents = [];

        foreach ($machines as $car) {
            $myLane    = $car['lane'];
            $otherLane = 1 - $myLane;
            $pos       = $car['position'];
            $v         = $car['speed'];

            $gapHere        = $this->gapForward($grid, $myLane, $pos, $roadLength);
            $gapOtherFwd    = $this->gapForward($grid, $otherLane, $pos, $roadLength);
            $gapOtherBack   = $this->gapBackward($grid, $otherLane, $pos, $roadLength);

            if ($myLane === 0) {
                // Мотивация: уперлись или уперёмся
                $motivation = $gapHere < min($v + 1, $vMax);
                // Соседняя полоса свободнее
                $incentive  = $gapOtherFwd > $gapHere;
                // Безопасно сзади (не влетит быстрый сосед)
                $safeBack   = $gapOtherBack >= $vMax;
                // Целевая клетка свободна
                $cellFree   = !isset($grid[$otherLane][$pos]);

                if ($motivation && $incentive && $safeBack && $cellFree) {
                    if (mt_rand() / mt_getrandmax() < $pChange) {
                        $intents[$car['id']] = $otherLane;
                    }
                }
            } else {
                // Возврат в правую: стандартное правило STCA — хватит места для текущей v
                $safeBack = $gapOtherBack >= $vMax;
                $safeFwd  = $gapOtherFwd >= $v;
                $cellFree = !isset($grid[$otherLane][$pos]);

                if ($safeBack && $safeFwd && $cellFree) {
                    if (mt_rand() / mt_getrandmax() < $pChange) {
                        $intents[$car['id']] = $otherLane;
                    }
                }
            }
        }

        return $intents;
    }

    /**
     * Этап 2: разрешение конфликтов — если двое целятся в одну клетку полосы, оба остаются.
     * (Альтернатива: случайно выбрать одного. Я реализую консервативный вариант — оба откат.)
     */
    private function resolveConflicts(array $machines, array $intents): array
    {
        // targetCells[lane][pos] = [carId, carId, ...]
        $targetCells = [];
        foreach ($machines as $car) {
            $id = $car['id'];
            if (!isset($intents[$id])) continue;
            $targetLane = $intents[$id];
            $targetCells[$targetLane][$car['position']][] = $id;
        }

        // Отсекаем коллизии
        foreach ($targetCells as $lane => $cells) {
            foreach ($cells as $pos => $ids) {
                if (count($ids) > 1) {
                    foreach ($ids as $id) unset($intents[$id]);
                }
            }
        }

        // Применяем
        foreach ($machines as &$car) {
            if (isset($intents[$car['id']])) {
                $car['lane'] = $intents[$car['id']];
            }
        }
        unset($car);

        return $machines;
    }

    /**
     * Шаг смены полосы целиком.
     */
    private function changeLanes(
        array $machines,
        int $roadLength,
        int $vMax,
        float $pChange
    ): array {
        $grid    = $this->buildGrid($machines, $roadLength);
        $intents = $this->collectLaneChangeIntents($machines, $grid, $roadLength, $vMax, $pChange);
        return $this->resolveConflicts($machines, $intents);
    }

    public function calculateStep(
        array $initialState,
        int $roadLength,
        int $iterations,
        int $vMax,
        float $p = 0.3,
        float $pChange = 1.0
    ): array {
        $history = [];
        $currentMachines = $initialState;
        $history[] = $currentMachines;

        for ($t = 0; $t < $iterations; $t++) {

            // 1. Фаза смены полосы (синхронно, с разрешением конфликтов)
            $currentMachines = $this->changeLanes($currentMachines, $roadLength, $vMax, $pChange);

            // 2. Фаза продольного движения: отдельно по каждой полосе
            $grid = $this->buildGrid($currentMachines, $roadLength);
            $nextMachinesState = [];

            foreach ($currentMachines as $car) {
                $gap = $this->gapForward($grid, $car['lane'], $car['position'], $roadLength);

                $v = $car['speed'];
                $v = $this->speedup($v, $vMax);
                $v = $this->slowdown($v, $gap);
                $v = $this->random($v, $p);

                $nextMachinesState[] = [
                    'id'       => $car['id'],
                    'lane'     => $car['lane'],
                    'speed'    => $v,
                    'position' => $this->move($car['position'], $v, $roadLength),
                ];
            }

            $history[] = $nextMachinesState;
            $currentMachines = $nextMachinesState;
        }

        return $history;
    }
}
