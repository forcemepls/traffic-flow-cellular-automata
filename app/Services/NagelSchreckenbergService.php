<?php

namespace App\Services;

use App\Support\NaSchRulesTrait;

class NagelSchreckenbergService
{
    use NaSchRulesTrait;

    protected function computeGap(array $car, array $sortedByPosition, int $roadLength): int
    {
        $n = count($sortedByPosition);
        $idx = null;
        foreach ($sortedByPosition as $i => $c) {
            if ($c['id'] === $car['id']) { $idx = $i; break; }
        }
        if ($idx === null) return $roadLength;

        $leader = $sortedByPosition[($idx + 1) % $n];

        if ($leader['position'] > $car['position']) {
            return $leader['position'] - $car['position'] - 1;
        }
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
            $sorted = $currentMachines;
            usort($sorted, fn($a, $b) => $a['position'] <=> $b['position']);

            $nextMachinesState = [];

            foreach ($currentMachines as $machine) {
                $v   = $machine['speed'];
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
