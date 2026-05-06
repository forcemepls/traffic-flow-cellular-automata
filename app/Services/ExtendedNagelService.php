<?php

namespace App\Services;

class ExtendedNagelService extends NagelSchreckenbergService
{
    // NaSchRulesTrait уже подключен через родительский класс —
    // speedup/slowdown/random/move доступны напрямую

    private function buildGrid(array $machines, int $roadLength): array
    {
        $grid = [0 => [], 1 => []];
        foreach ($machines as $car) {
            $grid[$car['lane']][$car['position']] = $car;
        }
        return $grid;
    }

    private function gapForward(array $grid, int $lane, int $pos, int $roadLength): int
    {
        for ($i = 1; $i <= $roadLength; $i++) {
            if (isset($grid[$lane][($pos + $i) % $roadLength])) {
                return $i - 1;
            }
        }
        return $roadLength;
    }

    private function gapBackward(array $grid, int $lane, int $pos, int $roadLength): int
    {
        for ($i = 1; $i <= $roadLength; $i++) {
            if (isset($grid[$lane][($pos - $i + $roadLength) % $roadLength])) {
                return $i - 1;
            }
        }
        return $roadLength;
    }

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

            $gapHere      = $this->gapForward($grid, $myLane,    $pos, $roadLength);
            $gapOtherFwd  = $this->gapForward($grid, $otherLane, $pos, $roadLength);
            $gapOtherBack = $this->gapBackward($grid, $otherLane, $pos, $roadLength);

            if ($myLane === 0) {
                $motivation = $gapHere < min($v + 1, $vMax);
                $incentive  = $gapOtherFwd > $gapHere;
                $safeBack   = $gapOtherBack >= $vMax;
                $cellFree   = !isset($grid[$otherLane][$pos]);

                if ($motivation && $incentive && $safeBack && $cellFree) {
                    if (mt_rand() / mt_getrandmax() < $pChange) {
                        $intents[$car['id']] = $otherLane;
                    }
                }
            } else {
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

    private function resolveConflicts(array $machines, array $intents): array
    {
        $targetCells = [];
        foreach ($machines as $car) {
            $id = $car['id'];
            if (!isset($intents[$id])) continue;
            $targetLane = $intents[$id];
            $targetCells[$targetLane][$car['position']][] = $id;
        }

        foreach ($targetCells as $lane => $cells) {
            foreach ($cells as $pos => $ids) {
                if (count($ids) > 1) {
                    foreach ($ids as $id) unset($intents[$id]);
                }
            }
        }

        foreach ($machines as &$car) {
            if (isset($intents[$car['id']])) {
                $car['lane'] = $intents[$car['id']];
            }
        }
        unset($car);

        return $machines;
    }

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
        $history        = [];
        $currentMachines = $initialState;
        $history[]      = $currentMachines;

        for ($t = 0; $t < $iterations; $t++) {
            $currentMachines = $this->changeLanes($currentMachines, $roadLength, $vMax, $pChange);

            $grid              = $this->buildGrid($currentMachines, $roadLength);
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

            $history[]       = $nextMachinesState;
            $currentMachines = $nextMachinesState;
        }

        return $history;
    }
}
