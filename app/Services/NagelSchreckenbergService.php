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
        if ($currentSpeed > 0 && (rand(0, 100) / 100) < $p) {
            return $currentSpeed - 1;
        }
        return $currentSpeed;
    }

    protected function move(int $position, int $speed, int $roadLength): int
    {
        $newPos = $position + $speed;

        if ($newPos >= $roadLength) {
            $newPos = $newPos - $roadLength;
        }

        return $newPos;
    }

    public function calculateStep(array $initialState, int $roadLength, int $iterations, int $vMax): array
    {
        $history = [];
        $currentMachines = $initialState;
        $history[] = $currentMachines;

        for ($t = 0; $t < $iterations; $t++) {

            usort($currentMachines, function ($a, $b) {
                return $a['position'] <=> $b['position'];
            });

            $nextMachinesState = [];
            $totalCars = count($currentMachines);

            for ($i = 0; $i < $totalCars; $i++) {

                $machine = $currentMachines[$i];

                $leaderIndex = ($i + 1) % $totalCars;
                $leader = $currentMachines[$leaderIndex];

                $v = $machine['speed'];
                $v = $this->speedup($v, $vMax);

                if ($leaderIndex == 0) {
                    $gap = ($roadLength - $machine['position']) + $leader['position'] - 1;
                } else {
                    $gap = $leader['position'] - $machine['position'] - 1;
                }

                $v = $this->slowdown($v, $gap);
                $v = $this->random($v, 0.3);

                $nextMachinesState[] = [
                    'id' => $machine['id'],
                    'speed' => $v,
                    'position' => $machine['position'],
                    'lane' => $machine['lane'] ?? 0,  // ДОБАВЛЕНО: сохраняем lane
                ];
            }

            foreach ($nextMachinesState as $key => $m) {
                $nextMachinesState[$key]['position'] = $this->move($m['position'], $m['speed'], $roadLength);
            }

            $history[] = $nextMachinesState;
            $currentMachines = $nextMachinesState;
        }

        return $history;
    }
}
