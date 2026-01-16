<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\NagelSchreckenbergService;

class NagelController extends Controller
{

    public function main(){

        $roadLength = 15;
        $iterations = 10;
        $vMax = 4;
        $ca = [];

        for ($i = 0; $i < $roadLength; $i++){
            $ca[] = 0;
        }

        $service = new NagelSchreckenbergService();

        $machines = [
            [
                'id' => 1,
                'speed' => 0,
                'position' => 13,
            ],
            [
                'id' => 0,
                'speed' => 0,
                'position' => 2,
            ],
            [
                'id' => 2,
                'speed' => 0,
                'position' => 9,
            ],
        ];

        $newState = $service->calculateStep($machines, $roadLength, $iterations, $vMax);

        $history = $service->calculateStep($machines, $roadLength, $iterations, $vMax);

        // --- БЛОК ОТЛАДКИ (Визуализация в браузере) ---
        echo "<pre style='font-family: monospace; font-size: 16px; line-height: 1.5;'>";
        echo "Road Length: {$roadLength}, Max Speed: {$vMax}<br><br>";

        foreach ($history as $stepIndex => $stepData) {
            // 1. Создаем пустую дорогу
            // array_fill создает массив из точек ['.', '.', '.', ...]
            $roadVisual = array_fill(0, $roadLength, '.');

            // 2. Расставляем машины
            foreach ($stepData as $car) {
                // Если машина на этой позиции, ставим её ID (или 'X', если наложение)
                $pos = $car['position'];
                $id = $car['id'];
                
                // Проверка на аварию (для отладки): если в клетке уже не точка
                if ($roadVisual[$pos] !== '.') {
                    $roadVisual[$pos] = 'CRASH'; 
                } else {
                    $roadVisual[$pos] = "<b>{$id}</b>"; // Жирный шрифт для машины
                }
            }

            // 3. Выводим строку
            echo "Step {$stepIndex}: [" . implode(' ', $roadVisual) . "] <br>";
            
            // Дополнительно можно вывести параметры машин текстом
            // foreach ($stepData as $car) { echo "(ID:{$car['id']} v:{$car['speed']} x:{$car['position']}) "; }
            // echo "<br><br>";
        }
        echo "</pre>";
        die(); // Останавливаем выполнение, чтобы Laravel не пытался рендерить View
    }
}
