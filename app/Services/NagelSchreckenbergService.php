<?php

namespace App\Services;

class NagelSchreckenbergService
{
    protected function speedup(int $currentSpeed, int $vMax): int
    {
        return min($currentSpeed + 1, $vMax);
    }

    protected function slowdown(int $currentSpeed, int $gap): int
    {
        return min($currentSpeed, $gap);
    }

    protected function random(int $currentSpeed, float $p): int
    {
        if ($currentSpeed > 0 && mt_rand() / mt_getrandmax() < $p) {
            return $currentSpeed - 1;
        }
        return $currentSpeed;
    }

    protected function move(int $position, int $speed, int $roadLength): int
    {
        return ($position + $speed) % $roadLength;
    }

    /**
     * Рассчитать gap до лидера для машины $car,
     * используя отсортированный по позиции массив.
     */
    protected function computeGap(array $car, array $sortedByPosition, int $roadLength): int
    {
        $n = count($sortedByPosition);
        // Находим индекс текущей машины в отсортированном массиве по id
        $idx = null;
        foreach ($sortedByPosition as $i => $c) {
            if ($c['id'] === $car['id']) { $idx = $i; break; }
        }
        if ($idx === null) return $roadLength;

        $leader = $sortedByPosition[($idx + 1) % $n];

        if ($leader['position'] > $car['position']) {
            return $leader['position'] - $car['position'] - 1;
        }
        // Лидер "за горизонтом" — завернулся через 0
        return ($roadLength - $car['position']) + $leader['position'] - 1;
    }

    public function calculateStep(
        array $initialState,
        int $roadLength,
        int $iterations,
        int $vMax,
        float $p = 0.3
    ): array {
        $history = [];
        $currentMachines = $initialState;
        $history[] = $currentMachines;

        for ($t = 0; $t < $iterations; $t++) {

            // Вспомогательная копия для поиска лидеров
            $sorted = $currentMachines;
            usort($sorted, fn($a, $b) => $a['position'] <=> $b['position']);

            $nextMachinesState = [];

            // Итерируем в исходном порядке (сохраняем id-ordering)
            foreach ($currentMachines as $machine) {
                $v = $machine['speed'];
                $gap = $this->computeGap($machine, $sorted, $roadLength);

                $v = $this->speedup($v, $vMax);
                $v = $this->slowdown($v, $gap);
                $v = $this->random($v, $p);

                $nextMachinesState[] = [
                    'id'       => $machine['id'],
                    'speed'    => $v,
                    'position' => $this->move($machine['position'], $v, $roadLength),
                    'lane'     => $machine['lane'] ?? 0,
                ];
            }

            $history[] = $nextMachinesState;
            $currentMachines = $nextMachinesState;
        }

        return $history;
    }
}
