<?php

namespace App\Services;

class NagelSchreckenbergService
{

    protected function speedup(int $currentSpeed, int $vMax): int{  // 1. ускорение
        return min($currentSpeed + 1, $vMax);
    }

    protected function slowdown(int $currentSpeed, int $gap): int{  // 2. торможение
        return  min($currentSpeed, $gap);
    }

    protected function random(int $currentSpeed, float $p): int{  // 3. случайное событие
        if ($currentSpeed > 0 && (rand(0, 100) / 100) < $p){
            return $currentSpeed - 1;
        }
        return $currentSpeed;
    }

    protected function move(int $position, int $speed, int $roadLength){  // 4. движение
        $newPos = $position + $speed;

        if ($newPos >= $roadLength) {
            $newPos = $newPos - $roadLength;
        }

        return $newPos;
    }

    public function calculateStep(array $initialState, int $roadLength, int $iterations, int $vMax){
        $history = [];  // здесь будет состояние дороги на каждом шаге

        $currentMachines = $initialState;  // текущее состояние машин

        $history[] = $currentMachines;  // текущее состояние в историю

        for ($t = 0; $t < $iterations; $t++){
            
            // сортировка по месту в КА
            usort($currentMachines, function($a, $b) {
                return $a['position'] <=> $b['position'];
            });

            $nextMachinesState = [];
            $totalCars = count($currentMachines);

            for ($i = 0; $i < $totalCars; $i++){

                $machine = $currentMachines[$i];

                // определение индекса лидера
                // ели текущая машина не последняя - (%i + 1)
                // если последнияя - беру первого (0) - замыкаю кольцо
                $leaderIndex = ($i + 1) % $totalCars;
                $leader = $currentMachines[$leaderIndex];

                $v = $machine['speed'];
                $v = $this->speedup($v, $vMax);

                if ($leaderIndex == 0){
                    $gap = ($roadLength - $machine['position']) + $leader['position'] - 1;
                } else{
                    $gap = $leader['position'] - $machine['position'] - 1;
                }

                $v = $this->slowdown($v, $gap);

                $v = $this->random($v, 0.3);

                $nextMachinesState[] = [
                    'id' => $machine['id'],
                    'speed' => $v,
                    'position' => $machine['position'],
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