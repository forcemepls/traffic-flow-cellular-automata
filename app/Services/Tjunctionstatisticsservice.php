<?php

namespace App\Services;

/**
 * Статистика по T-образному перекрёстку.
 *
 * Метрики ориентированы на регулируемый перекрёсток (разомкнутые
 * потоки, спавн/выезд, фазы светофора), а не на кольцевую модель:
 *
 *   1. Средняя скорость потока (по шагам)
 *   2. Коэффициент заторов (доля машин с v=0)
 *   3. Длины очередей по плечам W / E / S
 *   4. Пропускная способность по фазам (нараст. итог пересечений
 *      узла в фазах MAIN и SEC) — Webster 1958, HCM 2010
 *   5. Среднее время ожидания на въездах (control delay) по плечам
 *   6. Баланс системы: создано / выехало / в системе
 *   7. Распределение фактически выполненных манёвров (W→E, W→S, ...)
 *   8. Фундаментальная диаграмма: ρ — среднее число машин в системе,
 *      делённое на суммарную длину дорог; q — средний поток на выходе
 *
 * Сервис плоский, без DI. Принимает историю снапшотов и возвращает
 * массив, по структуре идентичный StatisticsService для кольцевых
 * (поля summary, perStep, ... — чтобы фронтенд переиспользовал
 * существующую модалку с минимальными правками).
 */
class TJunctionStatisticsService
{
    private const ARMS = ['W', 'E', 'S'];

    public function calculate(
        array $history,
        int $roadLength,
        int $vMax,
        array $phases  // ['main' => stepsMain, 'sec' => stepsSec] — для нормировки
    ): array {
        $totalSteps = count($history);
        if ($totalSteps < 2) {
            return $this->emptyStatistics();
        }

        // Пошаговые ряды
        $perStep = [
            'speed'           => [],   // средняя скорость по едущим
            'congestionRate'  => [],   // доля v=0
            'queueW'          => [],
            'queueE'          => [],
            'queueS'          => [],
            'throughputMain'  => [],   // нарастающий итог
            'throughputSec'   => [],
            'created'         => [],
            'exited'          => [],
            'inSystem'        => [],
        ];

        // Учёт времени ожидания: id → [waitSteps, armOnEntry]
        $waitTracking = [];
        $finishedWaits = []; // [arm => [waitSteps, ...]]
        foreach (self::ARMS as $arm) $finishedWaits[$arm] = [];

        // Учёт фактических манёвров
        $manoeuvreCounts = [
            'W->E' => 0, 'W->S' => 0,
            'E->W' => 0, 'E->S' => 0,
            'S->W' => 0, 'S->E' => 0,
        ];

        // Кумулятивные счётчики пересечений узла
        $thrMain = 0;
        $thrSec  = 0;

        // Чтобы определить «пересечение узла» — машина в этом шаге
        // имеет inJunction=true ИЛИ только что вышла на DIR_OUT (с
        // предыдущего шага она была inJunction). Берём момент выхода
        // (inJunction true → false), это однозначное событие на машину.
        $prevSnapshot = null;

        // Для финального summary
        $totalCreated = 0;
        $totalExited  = 0;

        foreach ($history as $step => $snap) {
            $machines = $snap['machines'] ?? [];
            $phase    = $snap['phase']    ?? 'main';
            $spawned  = $snap['spawned']  ?? 0;
            $exited   = $snap['finished'] ?? 0;

            $totalCreated = max($totalCreated, $spawned);
            $totalExited  = max($totalExited,  $exited);

            // --- 1. Средняя скорость
            $speeds = array_column($machines, 'speed');
            $n      = count($speeds);
            $avgSpeed = $n > 0 ? array_sum($speeds) / $n : 0.0;
            $perStep['speed'][] = round($avgSpeed, 2);

            // --- 2. Заторы
            $stopped = $n > 0 ? count(array_filter($speeds, fn($v) => $v === 0)) : 0;
            $perStep['congestionRate'][] = $n > 0 ? round($stopped / $n, 3) : 0;

            // --- 3. Очереди: машины на DIR_IN + ещё не въехавшие из буфера
            $queues = ['W' => 0, 'E' => 0, 'S' => 0];
            foreach ($machines as $car) {
                if (($car['inJunction'] ?? false) === true) continue;
                if (($car['dir'] ?? '') !== 'in') continue;
                $arm = $car['arm'] ?? null;
                if (isset($queues[$arm])) $queues[$arm]++;
            }
            // + буфер (машины, появившиеся в очереди но ещё не на дороге)
            $bufQ = $snap['queues'] ?? [];
            foreach (self::ARMS as $arm) {
                $queues[$arm] += (int)($bufQ[$arm] ?? 0);
            }
            $perStep['queueW'][] = $queues['W'];
            $perStep['queueE'][] = $queues['E'];
            $perStep['queueS'][] = $queues['S'];

            // --- 5. Время ожидания (накапливаем v=0 на DIR_IN)
            foreach ($machines as $car) {
                $id = $car['id'] ?? null;
                if ($id === null) continue;
                if (($car['inJunction'] ?? false) === true) continue;

                if (($car['dir'] ?? '') === 'in') {
                    if (!isset($waitTracking[$id])) {
                        $waitTracking[$id] = ['wait' => 0, 'arm' => $car['arm']];
                    }
                    if (($car['speed'] ?? 0) === 0) {
                        $waitTracking[$id]['wait']++;
                    }
                }
            }

            // --- 4 + 7. Пересечения узла: ловим переход inJunction true→false
            //              в этом случае машина выехала на DIR_OUT целевого плеча.
            if ($prevSnapshot !== null) {
                $prevById = [];
                foreach ($prevSnapshot['machines'] ?? [] as $c) {
                    $prevById[$c['id']] = $c;
                }
                $prevPhase = $prevSnapshot['phase'] ?? 'main';

                foreach ($machines as $car) {
                    $id   = $car['id'] ?? null;
                    if ($id === null) continue;
                    if (!isset($prevById[$id])) continue;
                    $was  = $prevById[$id];

                    $wasInJ = $was['inJunction']  ?? false;
                    $isInJ  = $car['inJunction']  ?? false;

                    if ($wasInJ && !$isInJ) {
                        // Засчитываем фазу, в которой произошло пересечение —
                        // это та фаза, в которой машина была inJunction
                        // (т.е. предыдущая фаза снапшота).
                        if ($prevPhase === 'main') $thrMain++;
                        else                       $thrSec++;

                        // Манёвр: arm у машины — целевое плечо после выхода;
                        // before было исходное.
                        $fromArm = $was['arm'] ?? null;
                        $toArm   = $car['arm'] ?? null;
                        if ($fromArm && $toArm && $fromArm !== $toArm) {
                            $key = $fromArm . '->' . $toArm;
                            if (isset($manoeuvreCounts[$key])) $manoeuvreCounts[$key]++;
                        }

                        // Закрываем счётчик ожидания (берём wait из учёта)
                        if (isset($waitTracking[$id])) {
                            $arm = $waitTracking[$id]['arm'];
                            $finishedWaits[$arm][] = $waitTracking[$id]['wait'];
                            unset($waitTracking[$id]);
                        }
                    }
                }
            }

            $perStep['throughputMain'][] = $thrMain;
            $perStep['throughputSec'][]  = $thrSec;

            // --- 6. Баланс
            $perStep['created'][]  = $totalCreated;
            $perStep['exited'][]   = $totalExited;
            $perStep['inSystem'][] = $n;

            $prevSnapshot = $snap;
        }

        // ── Сводная статистика ──
        $avgSpeed         = $this->avg($perStep['speed']);
        $avgCongestion    = $this->avg($perStep['congestionRate']);
        $avgQueueW        = $this->avg($perStep['queueW']);
        $avgQueueE        = $this->avg($perStep['queueE']);
        $avgQueueS        = $this->avg($perStep['queueS']);

        $stepsMain = max(1, $phases['main'] ?? 1);
        $stepsSec  = max(1, $phases['sec']  ?? 1);
        $cycleLen  = $stepsMain + $stepsSec;
        $cyclesDone = $totalSteps / max(1, $cycleLen);

        // Throughput / hour: 1 шаг = 1 сек. Поток = пересечений * 3600 / шагов фазы
        // Считаем пересечения по фазе и делим на суммарное время этой фазы.
        $stepsInPhaseMain = 0;
        $stepsInPhaseSec  = 0;
        foreach ($history as $snap) {
            if (($snap['phase'] ?? 'main') === 'main') $stepsInPhaseMain++;
            else                                       $stepsInPhaseSec++;
        }
        $throughputMainPerHour = $stepsInPhaseMain > 0
            ? round($thrMain * 3600 / $stepsInPhaseMain, 1) : 0;
        $throughputSecPerHour  = $stepsInPhaseSec > 0
            ? round($thrSec  * 3600 / $stepsInPhaseSec,  1) : 0;

        // Время ожидания: средние по плечам + общее
        $avgWaitByArm = ['W' => 0.0, 'E' => 0.0, 'S' => 0.0];
        $allWaits = [];
        foreach (self::ARMS as $arm) {
            if (!empty($finishedWaits[$arm])) {
                $avgWaitByArm[$arm] = round($this->avg($finishedWaits[$arm]), 2);
                $allWaits = array_merge($allWaits, $finishedWaits[$arm]);
            }
        }
        $avgWaitTotal = !empty($allWaits) ? round($this->avg($allWaits), 2) : 0.0;

        // Фундаментальная диаграмма
        // ρ — среднее число машин в системе / суммарная длина (3 плеча × 2 dir × roadLength,
        //     S — 1+1, W/E — 2+2 → суммарно 10 * roadLength клеток).
        $totalCells = 10 * $roadLength;
        $avgInSystem = $this->avg($perStep['inSystem']);
        $density     = $totalCells > 0 ? $avgInSystem / $totalCells : 0;
        // q — средний поток на выходе: всего выехало / шагов
        $flow = $totalSteps > 0 ? $totalExited / $totalSteps : 0;

        $summary = [
            'avgSpeed'              => round($avgSpeed, 2),
            'avgCongestionRate'     => round($avgCongestion, 3),
            'avgQueueW'             => round($avgQueueW, 2),
            'avgQueueE'             => round($avgQueueE, 2),
            'avgQueueS'             => round($avgQueueS, 2),
            'throughputMain'        => $thrMain,
            'throughputSec'         => $thrSec,
            'throughputMainPerHour' => $throughputMainPerHour,
            'throughputSecPerHour'  => $throughputSecPerHour,
            'avgWaitTotal'          => $avgWaitTotal,
            'avgWaitW'              => $avgWaitByArm['W'],
            'avgWaitE'              => $avgWaitByArm['E'],
            'avgWaitS'              => $avgWaitByArm['S'],
            'totalCreated'          => $totalCreated,
            'totalExited'           => $totalExited,
            'finalInSystem'         => end($perStep['inSystem']) ?: 0,
            'cyclesCompleted'       => round($cyclesDone, 1),
            'vMax'                  => $vMax,
        ];

        $fundamentalDiagram = [
            'density' => round($density, 4),
            'flow'    => round($flow, 3),
            'speed'   => round($avgSpeed, 2),
        ];

        return [
            'summary'            => $summary,
            'perStep'            => $perStep,
            'manoeuvres'         => $manoeuvreCounts,
            'fundamentalDiagram' => $fundamentalDiagram,
            'meta' => [
                'totalSteps'   => $totalSteps,
                'roadLength'   => $roadLength,
                'phaseSteps'   => ['main' => $stepsMain, 'sec' => $stepsSec],
            ],
        ];
    }

    private function avg(array $xs): float
    {
        return empty($xs) ? 0.0 : array_sum($xs) / count($xs);
    }

    private function emptyStatistics(): array
    {
        return [
            'summary' => [
                'avgSpeed' => 0, 'avgCongestionRate' => 0,
                'avgQueueW' => 0, 'avgQueueE' => 0, 'avgQueueS' => 0,
                'throughputMain' => 0, 'throughputSec' => 0,
                'throughputMainPerHour' => 0, 'throughputSecPerHour' => 0,
                'avgWaitTotal' => 0, 'avgWaitW' => 0, 'avgWaitE' => 0, 'avgWaitS' => 0,
                'totalCreated' => 0, 'totalExited' => 0, 'finalInSystem' => 0,
                'cyclesCompleted' => 0, 'vMax' => 0,
            ],
            'perStep' => [
                'speed' => [], 'congestionRate' => [],
                'queueW' => [], 'queueE' => [], 'queueS' => [],
                'throughputMain' => [], 'throughputSec' => [],
                'created' => [], 'exited' => [], 'inSystem' => [],
            ],
            'manoeuvres' => [
                'W->E' => 0, 'W->S' => 0,
                'E->W' => 0, 'E->S' => 0,
                'S->W' => 0, 'S->E' => 0,
            ],
            'fundamentalDiagram' => ['density' => 0, 'flow' => 0, 'speed' => 0],
            'meta' => ['totalSteps' => 0, 'roadLength' => 0, 'phaseSteps' => ['main' => 0, 'sec' => 0]],
        ];
    }
}
