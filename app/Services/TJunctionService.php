<?php

namespace App\Services;

use App\Support\NaSchRulesTrait;

/**
 * T-образный перекрёсток со светофорным регулированием.
 *
 * Геометрия (правостороннее движение):
 *
 *   W-плечо (4 полосы)                        E-плечо (4 полосы)
 *   ─── DIR_OUT lane THROUGH ──────────────── DIR_OUT lane THROUGH ───
 *   ─── DIR_OUT lane TURN  ────────────────── DIR_OUT lane TURN ──────
 *   ═══ (сплошная разметка между встречными) ════════════════════════
 *   ─── DIR_IN  lane TURN  (для поворота на S) ── DIR_IN lane TURN ───
 *   ─── DIR_IN  lane THROUGH (для прямого W↔E) ── DIR_IN lane THROUGH ─
 *                                  │  узел  │
 *                                  │ 4×2 LW │
 *                       DIR_IN  ── │        │ ── DIR_OUT
 *                       (↑ к узлу) │        │ (↓ от узла)
 *                                  │        │
 *                                S-плечо (1+1)
 *
 * Полоса жёстко связана с фазой светофора (стрелочные секции):
 *   main: разрешает W→E, E→W (только машинам на LANE_THROUGH W/E)
 *   sec : разрешает S→W, S→E, W→S, E→S (на W/E — только LANE_TURN)
 *
 * Если машина не успела перестроиться к стоп-линии, её манёвр
 * корректируется по факту полосы (правая → прямо, левая → на S),
 * как в реальной дорожной разметке.
 *
 * Конфликты в узле во второстепенной фазе:
 *   W→S vs S→E — пересекаются. Приоритет по waitedAt.
 *   E→S vs S→W — симметрично.
 *   W→S vs E→S — обе целят в S DIR_OUT[0] (resolveJunctionConflicts по goal).
 */
class TJunctionService
{
    use NaSchRulesTrait;

    // Направления движения
    const DIR_IN  = 'in';   // въездная полоса (к узлу)
    const DIR_OUT = 'out';  // выездная полоса (от узла)

    // Плечи
    const ARM_W = 'W';
    const ARM_E = 'E';
    const ARM_S = 'S';

    // -------------------------------------------------------
    // Полосы на плечах W и E (S остаётся однополосным).
    //
    // На W/E DIR_IN и DIR_OUT — двухполосные:
    //   LANE_THROUGH = 0  — внешняя (правая по ходу), для прямого проезда W↔E
    //   LANE_TURN    = 1  — внутренняя (левая по ходу), для поворота на S
    //
    // Полоса жёстко привязана к фазе светофора (как стрелочные секции):
    //   PHASE_MAIN — на W/E едет только LANE_THROUGH (W→E, E→W).
    //   PHASE_SEC  — на W/E едет только LANE_TURN (W→S, E→S),
    //                плюс всё плечо S (там полосы нет).
    //
    // Маршрут (goal) назначается при спавне, полоса — случайная.
    // Перестроение — асимметричное (Chowdhury–Wolf–Schreckenberg 1997 +
    // Rickert et al. 1996, но с императивом маршрута):
    // машина каждый шаг пытается уйти на «свою» полосу, если не успела
    // до стоп-линии — goal корректируется по факту полосы (правая →
    // прямо, левая → поворот). Это эквивалент реальной разметки:
    // выехал на поворотную — обязан повернуть, и наоборот.
    // -------------------------------------------------------
    const LANE_THROUGH = 0;  // правая, для W↔E
    const LANE_TURN    = 1;  // левая, для поворота на S
    const LANE_SINGLE  = 0;  // S-плечо: всегда 0

    // Безопасный gap для перестроения. Стандартные правила
    // Чоудхури-Вольфа-Шрекенберга: gap_other_ahead ≥ speed,
    // gap_other_back ≥ vMax другой машины. У нас vMax общий —
    // используем его. Возле стоп-линии (≤ AGGRESSIVE_DIST клеток)
    // требования смягчаются, иначе машина не успеет перестроиться.
    const LANE_CHANGE_AGGRESSIVE_DIST = 6;

    // Распределение целей на въездах
    const GOAL_DISTRIBUTION = [
        self::ARM_W => ['E' => 0.7, 'S' => 0.3],
        self::ARM_E => ['W' => 0.7, 'S' => 0.3],
        self::ARM_S => ['E' => 0.5, 'W' => 0.5],
    ];

    // Плавное торможение перед узлом (anticipation rule).
    //
    // Применяется в двух случаях:
    //   1) машина поворачивает (любой манёвр кроме сквозного W↔E),
    //   2) манёвр запрещён текущей фазой светофора (красный для неё).
    //
    // Формула: effectiveVMax = ceil(distToStopLine / BRAKE_RATE).
    // При BRAKE_RATE=2 получается линейная "лестница": 4 клетки → v≤2,
    // 6 клеток → v≤3, 8 → v≤4. Машина видит запрет/поворот заранее и
    // снижает скорость плавно, без обрыва в одну клетку.
    //
    // Это аналог anticipation rule из Knospe et al. 2000 (brake light
    // model): водитель реагирует не только на gap до лидера, но и на
    // внешний сигнал торможения — здесь таким сигналом служит сам
    // светофор и геометрия поворота.
    const BRAKE_RATE = 2;

    // Фазы
    const PHASE_MAIN = 'main';
    const PHASE_SEC  = 'sec';

    // -------------------------------------------------------
    // VDR (Velocity-Dependent Randomization, Barlovic et al. 1998)
    //
    // Ключевая идея модели: вероятность торможения зависит от скорости.
    // Стоящие машины (v=0) "тупят" на старте сильнее, чем едущие
    // случайно тормозят на ходу. В оригинальной работе:
    //     p_slow ∈ [0.5, 0.75],  p_drive ∈ [0.01, 0.1]
    //
    // Это даёт два важных эффекта:
    //  1) метастабильность свободного потока при средней плотности,
    //  2) реалистичные "медленные старты" из заторов и от светофора.
    //
    // P_SLOW_START — вероятность остаться на месте при v=0 (модель
    // задержки реакции водителя на зелёный/освобождение лидера).
    // p_drive приходит из UI и применяется к движущимся машинам;
    // дефолт в UI снижен до 0.1, чтобы движущиеся не "клевали" на
    // каждом шаге.
    // -------------------------------------------------------
    const P_SLOW_START = 0.5;
    const SPAWN_MIN_GAP = 6;

    // -------------------------------------------------------
    // Медленное ускорение
    //
    // ACCEL_INTERVAL = 1 — классический NaSch (+1 каждый шаг).
    // Само по себе ускорение +1 за шаг уже плавное.
    // -------------------------------------------------------
    const ACCEL_INTERVAL = 1;

    // ---------------------------------------------------------------
    // Публичный API
    // ---------------------------------------------------------------

    public function calculateStep(
        int $roadLength,
        int $iterations,
        int $vMax,
        float $p,
        int $tPhaseMain,
        int $tPhaseSec,
        float $lambdaW,
        float $lambdaE,
        float $lambdaS
    ): array {
        $state = $this->makeInitialState();

        $history   = [];
        $history[] = $this->makeSnapshot($state, 0);

        for ($t = 1; $t <= $iterations; $t++) {
            $state     = $this->doStep($state, $roadLength, $vMax, $p,
                $tPhaseMain, $tPhaseSec, $lambdaW, $lambdaE, $lambdaS);
            $history[] = $this->makeSnapshot($state, $t);
        }

        return $history;
    }

    // ---------------------------------------------------------------
    // Состояние
    // ---------------------------------------------------------------

    private function makeInitialState(): array
    {
        return [
            'machines'   => [],
            'queues'     => [self::ARM_W => [], self::ARM_E => [], self::ARM_S => []],
            'phase'      => self::PHASE_MAIN,
            'phaseTimer' => 0,
            'nextId'     => 0,
            'spawned'    => 0,
            'finished'   => 0,
        ];
    }

    private function doStep(
        array $state, int $roadLength, int $vMax, float $p,
        int $tPhaseMain, int $tPhaseSec,
        float $lambdaW, float $lambdaE, float $lambdaS
    ): array {
        $state = $this->updateTrafficLight($state, $tPhaseMain, $tPhaseSec);

        // Сначала двигаем уже существующих, удаляем доехавших.
        // Только после этого спавним новых и ставим их на въездную клетку 0.
        // Так свежепоявившаяся машина попадает в снапшот этого шага именно
        // на клетке 0 со speed=1 — и frontend рисует появление в нулевой
        // точке. Движение начнётся в следующем шаге.
        $state = $this->moveCars($state, $roadLength, $vMax, $p);
        $state = $this->removeCars($state, $roadLength);
        $state = $this->spawnCars($state, $lambdaW, $lambdaE, $lambdaS);
        $state = $this->flushQueues($state);

        return $state;
    }

    // ---------------------------------------------------------------
    // Светофор
    // ---------------------------------------------------------------

    private function updateTrafficLight(array $state, int $tMain, int $tSec): array
    {
        $duration = $state['phase'] === self::PHASE_MAIN ? $tMain : $tSec;
        $state['phaseTimer']++;

        if ($state['phaseTimer'] >= $duration) {
            $state['phase']      = $state['phase'] === self::PHASE_MAIN ? self::PHASE_SEC : self::PHASE_MAIN;
            $state['phaseTimer'] = 0;
        }
        return $state;
    }

    private function isManoeuvreAllowed(string $arm, string $goal, string $phase): bool
    {
        if ($phase === self::PHASE_MAIN) {
            // Только сквозное движение по главной
            return ($arm === self::ARM_W && $goal === self::ARM_E)
                || ($arm === self::ARM_E && $goal === self::ARM_W);
        }
        // PHASE_SEC: всё, что связано с южным плечом
        return ($arm === self::ARM_S)                                          // S→W, S→E
            || ($arm === self::ARM_W && $goal === self::ARM_S)                 // W→S
            || ($arm === self::ARM_E && $goal === self::ARM_S);                // E→S
    }

    // ---------------------------------------------------------------
    // Генерация машин
    // ---------------------------------------------------------------

    private function spawnCars(array $state, float $lW, float $lE, float $lS): array
    {
        $lambdas = [self::ARM_W => $lW, self::ARM_E => $lE, self::ARM_S => $lS];

        foreach ($lambdas as $arm => $lambda) {
            if ($lambda <= 0) continue;

            // Пуассоновский спавн: за шаг (1 секунду) вероятность p = λ/60
            $prob = min(1.0, $lambda / 60.0);
            if ((mt_rand() / mt_getrandmax()) >= $prob) continue;

            $goal = $this->pickGoal($arm);
            $lane = ($arm === self::ARM_S)
                ? self::LANE_SINGLE
                : (mt_rand(0, 1) === 0 ? self::LANE_THROUGH : self::LANE_TURN);

            // Не спавним, если въездная зона нужной полосы ещё занята
            // предыдущей машиной (на других полосах — без разницы).
            if ($this->isSpawnZoneBusy($state['machines'], $arm, $lane)) continue;

            $state['queues'][$arm][] = $this->makeCar($state['nextId'], $arm, $goal, $lane);
            $state['nextId']++;
            $state['spawned']++;
        }
        return $state;
    }

    /**
     * Зона перед въездом занята, если в первых SPAWN_MIN_GAP клетках
     * DIR_IN на нужной полосе уже стоит/едет машина. Это страхует от
     * спавна "впритык" и даёт лидеру разогнаться без помех от хвоста.
     *
     * Для W/E проверка ведётся по конкретной полосе (а не по плечу
     * целиком), иначе на двухполосном въезде поток падает в 2 раза.
     * Для S — единственная полоса.
     */
    private function isSpawnZoneBusy(array $machines, string $arm, int $lane): bool
    {
        foreach ($machines as $car) {
            if ($car['inJunction']) continue;
            if ($car['arm'] !== $arm) continue;
            if ($car['dir'] !== self::DIR_IN) continue;
            if (($car['lane'] ?? 0) !== $lane) continue;
            if ($car['position'] < self::SPAWN_MIN_GAP) {
                return true;
            }
        }
        return false;
    }

    private function pickGoal(string $arm): string
    {
        $rand = mt_rand() / mt_getrandmax();
        $cum  = 0.0;
        foreach (self::GOAL_DISTRIBUTION[$arm] as $goal => $p) {
            $cum += $p;
            if ($rand < $cum) return $goal;
        }
        return array_key_first(self::GOAL_DISTRIBUTION[$arm]);
    }

    private function makeCar(int $id, string $arm, string $goal, int $lane): array
    {
        return [
            'id'         => $id,
            'arm'        => $arm,
            'dir'        => self::DIR_IN,
            'position'   => 0,
            'lane'       => $lane,
            'speed'      => 1,      // въезжаем уже двигаясь, не с нуля
            'goal'       => $goal,
            'inJunction' => false,
            'waitedAt'   => null,   // момент, когда машина встала перед узлом (для FIFO)
            'accelTimer' => 0,      // счётчик для ACCEL_INTERVAL
            'justEntered'=> true,   // только что попала на дорогу — пропускаем VDR на 1 шаг
        ];
    }

    // ---------------------------------------------------------------
    // Слив очередей на въездную клетку 0
    // ---------------------------------------------------------------

    private function flushQueues(array $state): array
    {
        foreach ([self::ARM_W, self::ARM_E, self::ARM_S] as $arm) {
            if (empty($state['queues'][$arm])) continue;

            // На W/E две полосы — пытаемся слить сколько получится
            // (но за один проход берём только машины разных полос).
            // На S одна полоса — сливаем максимум одну.
            $occupiedLanes = $this->occupiedEntryLanes($state['machines'], $arm);

            $remaining = [];
            foreach ($state['queues'][$arm] as $car) {
                $lane = $car['lane'] ?? 0;
                if (!isset($occupiedLanes[$lane])) {
                    $state['machines'][$car['id']] = $car;
                    $occupiedLanes[$lane] = true;
                } else {
                    $remaining[] = $car;
                }
            }
            $state['queues'][$arm] = $remaining;
        }
        return $state;
    }

    /**
     * Возвращает map [lane => true] полос, на которых уже стоит
     * машина в клетке 0 DIR_IN указанного плеча.
     */
    private function occupiedEntryLanes(array $machines, string $arm): array
    {
        $occupied = [];
        foreach ($machines as $car) {
            if ($car['arm'] === $arm
                && $car['dir'] === self::DIR_IN
                && !$car['inJunction']
                && $car['position'] === 0) {
                $occupied[$car['lane'] ?? 0] = true;
            }
        }
        return $occupied;
    }

    // ---------------------------------------------------------------
    // Главный шаг: движение всех машин
    // ---------------------------------------------------------------

    private function moveCars(array $state, int $roadLength, int $vMax, float $p): array
    {
        $grid = $this->buildGrid($state['machines']);

        // Прогноз: какие клетки DIR_OUT[arm][lane][0] будут СВОБОДНЫ
        // после движения машин, уже стоящих на этих клетках. Машина в
        // узле не должна ждать "лишний шаг" из-за того, что лидер на
        // pos=0 уже планирует уехать в этом же шаге.
        $exitWillBeFree = [];
        foreach ([self::ARM_W, self::ARM_E, self::ARM_S] as $arm) {
            $lanes = ($arm === self::ARM_S) ? [self::LANE_SINGLE] : [self::LANE_THROUGH, self::LANE_TURN];
            foreach ($lanes as $lane) {
                $exitWillBeFree[$arm][$lane] = !isset($grid[$arm][self::DIR_OUT][$lane][0]);
            }
        }
        foreach ($state['machines'] as $car) {
            if ($car['inJunction']) continue;
            if ($car['dir'] !== self::DIR_OUT) continue;
            if ($car['position'] !== 0) continue;
            $arm  = $car['arm'];
            $lane = $car['lane'] ?? 0;
            if (!isset($grid[$arm][self::DIR_OUT][$lane][1])) {
                $exitWillBeFree[$arm][$lane] = true;
            }
        }

        // Перестроения планируем ДО продольного движения, на «пустом»
        // grid'е — это даёт детерминированный порядок и исключает
        // ложные конфликты с теми, кто только что съехал с полосы.
        $laneChanges = $this->planLaneChanges($state['machines'], $grid, $state['phase'], $roadLength, $vMax);

        // Применяем перестроения к локальной копии для дальнейшего
        // продольного планирования. Сами snapshots обновляем в самом конце.
        $machinesAfterLC = $state['machines'];
        foreach ($laneChanges as $id => $newLane) {
            $machinesAfterLC[$id]['lane'] = $newLane;
        }
        $grid = $this->buildGrid($machinesAfterLC);

        $intentions = [];

        foreach ($machinesAfterLC as $car) {
            if ($car['inJunction']) {
                $intentions[$car['id']] = $this->planExitJunction($car, $grid, $exitWillBeFree);
                continue;
            }
            if ($car['dir'] === self::DIR_IN) {
                $intentions[$car['id']] = $this->planInbound($car, $grid, $state['phase'], $roadLength, $vMax, $p, $machinesAfterLC);
            } else {
                $intentions[$car['id']] = $this->planOutbound($car, $grid, $roadLength, $vMax, $p);
            }
        }

        $intentions = $this->resolveJunctionConflicts($intentions, $machinesAfterLC);
        $intentions = $this->resolveExitConflicts($intentions, $machinesAfterLC);

        // Сначала фиксируем перестроения (они уже применены к machinesAfterLC),
        // потом применяем продольные интенты.
        foreach ($laneChanges as $id => $newLane) {
            $state['machines'][$id]['lane'] = $newLane;
        }
        foreach ($intentions as $id => $intent) {
            $state['machines'][$id] = array_merge($state['machines'][$id], $intent);
        }

        foreach ($state['machines'] as $id => &$car) {
            $atStopLine = !$car['inJunction']
                && $car['dir'] === self::DIR_IN
                && $car['position'] === $roadLength - 1
                && $car['speed'] === 0;
            if ($atStopLine && $car['waitedAt'] === null) {
                $car['waitedAt'] = $state['phaseTimer'];
            } elseif (!$atStopLine) {
                $car['waitedAt'] = null;
            }
        }
        unset($car);

        return $state;
    }

    /**
     * grid[arm][dir][lane][position] = carId
     *
     * Lane на S — всегда 0. На W/E — 0 (through) или 1 (turn).
     */
    private function buildGrid(array $machines): array
    {
        $grid = [];
        foreach ($machines as $car) {
            if ($car['inJunction']) continue;
            $lane = $car['lane'] ?? 0;
            $grid[$car['arm']][$car['dir']][$lane][$car['position']] = $car['id'];
        }
        return $grid;
    }

    // ---------------------------------------------------------------
    // Перестроения (Chowdhury-Wolf-Schreckenberg 1997, асимметричный
    // вариант с императивом маршрута).
    //
    // Каждая машина на DIR_IN W/E, чья текущая полоса не соответствует
    // её goal, пытается сменить полосу. Базовые условия:
    //   1) gap_ahead на текущей полосе < (нужный для движения),
    //      ИЛИ полоса в принципе "не та" — в нашем случае ВСЕГДА да,
    //      потому что мотив перестроения — маршрут, а не комфорт;
    //   2) на целевой полосе впереди (gap_other_ahead) ≥ speed машины,
    //   3) на целевой полосе сзади (gap_other_back) ≥ vMax (запас под
    //      встречную, идущую быстрее);
    //   4) клетка-мишень на целевой полосе свободна.
    //
    // Возле стоп-линии (≤ LANE_CHANGE_AGGRESSIVE_DIST) условие 3
    // смягчается до gap_back ≥ 1, иначе машина зависнет на чужой полосе
    // и придётся менять манёвр по факту полосы.
    // ---------------------------------------------------------------

    private function planLaneChanges(array $machines, array $grid, string $phase, int $roadLength, int $vMax): array
    {
        $changes = [];
        $reservedTargets = []; // [arm][lane][pos] = true — чтобы две машины не нацелились в одну клетку

        foreach ($machines as $car) {
            if ($car['inJunction']) continue;
            if ($car['dir'] !== self::DIR_IN) continue;
            if ($car['arm'] === self::ARM_S) continue; // S однополосное

            $currentLane = $car['lane'] ?? 0;
            $desiredLane = $this->desiredLaneFor($car['goal']);
            if ($currentLane === $desiredLane) continue;

            $arm = $car['arm'];
            $pos = $car['position'];
            $distToStopLine = ($roadLength - 1) - $pos;
            $aggressive = $distToStopLine <= self::LANE_CHANGE_AGGRESSIVE_DIST;

            // Целевая клетка — та же позиция, но на соседней полосе.
            // (Классический NaSch для двухполосной модели — перестроение
            // без продольного смещения, продольное движение делается
            // отдельным шагом.)
            if (isset($grid[$arm][self::DIR_IN][$desiredLane][$pos])) continue;
            if (isset($reservedTargets[$arm][$desiredLane][$pos])) continue;

            // gap впереди на целевой полосе (от нашей позиции вперёд)
            $gapAhead = $this->gapAheadOnLane($grid, $arm, self::DIR_IN, $desiredLane, $pos, $roadLength);
            if ($gapAhead < $car['speed']) continue;

            // gap позади на целевой полосе
            $gapBack    = $this->gapBackOnLane($grid, $arm, self::DIR_IN, $desiredLane, $pos);
            $minGapBack = $aggressive ? 1 : $vMax;
            if ($gapBack < $minGapBack) continue;

            $changes[$car['id']] = $desiredLane;
            $reservedTargets[$arm][$desiredLane][$pos] = true;
        }

        return $changes;
    }

    /**
     * Какая полоса соответствует маршруту на W/E.
     * Goal на S → LANE_TURN, иначе (W↔E сквозное) → LANE_THROUGH.
     */
    private function desiredLaneFor(string $goal): int
    {
        return $goal === self::ARM_S ? self::LANE_TURN : self::LANE_THROUGH;
    }

    private function gapAheadOnLane(array $grid, string $arm, string $dir, int $lane, int $pos, int $roadLength): int
    {
        for ($i = 1; $i <= $roadLength; $i++) {
            $check = $pos + $i;
            if ($check >= $roadLength) return $roadLength;
            if (isset($grid[$arm][$dir][$lane][$check])) return $i - 1;
        }
        return $roadLength;
    }

    private function gapBackOnLane(array $grid, string $arm, string $dir, int $lane, int $pos): int
    {
        for ($i = 1; $i <= $pos; $i++) {
            $check = $pos - $i;
            if (isset($grid[$arm][$dir][$lane][$check])) return $i - 1;
        }
        return PHP_INT_MAX;
    }

    // ---------------------------------------------------------------
    // NaSch + VDR + медленное ускорение
    //
    // Применяет 3 правила к скорости + обновляет accelTimer.
    // Возвращает [новая скорость, новый accelTimer].
    //
    // 1. Ускорение: +1, но только раз в ACCEL_INTERVAL шагов
    // 2. Торможение по gap: v = min(v, gap)  — мгновенное (как в NaSch)
    // 3. VDR-рандомизация: для v=0 — p=P_SLOW_START, для v>0 — p=$p
    //
    // $skipVDR — отключает VDR (для машин, которые только что появились
    // на полосе: после спавна или выхода из узла). Это исключает
    // нереалистичные «застревания» сразу после старта.
    // ---------------------------------------------------------------

    private function applyNaSchVDR(
        int $v, int $accelTimer, int $vMax, int $gap, float $p, bool $skipVDR = false
    ): array {
        // 1. Медленное ускорение
        $newAccelTimer = $accelTimer + 1;
        if ($v < $vMax && $newAccelTimer >= self::ACCEL_INTERVAL) {
            $v = min($v + 1, $vMax);
            $newAccelTimer = 0;
        }

        // 2. Торможение по gap (мгновенное)
        $v = $this->slowdown($v, $gap);

        // 3. VDR-рандомизация (если не отключена)
        if (!$skipVDR) {
            $vBefore = $v;
            $effectiveP = ($vBefore === 0) ? self::P_SLOW_START : $p;
            if ($v > 0 && (mt_rand() / mt_getrandmax()) < $effectiveP) {
                $v = $v - 1;
            } elseif ($vBefore === 0 && (mt_rand() / mt_getrandmax()) < $effectiveP) {
                // VDR для стоящих: остаёмся на 0 (не тронемся в этом шаге)
                $v = 0;
                $newAccelTimer = 0;
            }
        }

        return [$v, $newAccelTimer];
    }

    // ---------------------------------------------------------------
    // Планирование: машина на въездной полосе
    // ---------------------------------------------------------------

    private function planInbound(
        array $car, array $grid, string $phase, int $roadLength, int $vMax, float $p,
        array $machinesAfterLC = []
    ): array {
        $pos            = $car['position'];
        $lane           = $car['lane'] ?? 0;
        $distToStopLine = ($roadLength - 1) - $pos;  // 0 = вплотную к узлу

        // На W/E goal жёстко диктуется полосой при подходе к узлу:
        //   LANE_THROUGH → прямой проезд (W↔E),
        //   LANE_TURN    → поворот на S.
        // Если машина не успела перестроиться к стоп-линии — goal
        // корректируется по факту полосы (как реальная разметка).
        // На S — goal остаётся как был (плечо однополосное).
        $effectiveGoal = $this->effectiveGoalForLane($car['arm'], $lane, $car['goal']);

        $allowed   = $this->isManoeuvreAllowed($car['arm'], $effectiveGoal, $phase);
        $isTurning = $this->isTurning($car['arm'], $effectiveGoal);

        // Anticipation: плавно тормозим, если впереди узел требует
        // снижения скорости — поворот или красный для нашего манёвра.
        // Сквозной зелёный — едем на vMax без ограничения.
        $effectiveVMax = $vMax;
        if ($isTurning || !$allowed) {
            $brakingCap    = max(1, (int) ceil($distToStopLine / self::BRAKE_RATE));
            $effectiveVMax = min($vMax, $brakingCap);
        }

        // gap до лидера на той же полосе
        $gapToLeader = $this->gapAheadOnLane($grid, $car['arm'], self::DIR_IN, $lane, $pos, $roadLength);

        // gap до стоп-линии (если манёвр запрещён — стоп-линия в позиции roadLength-1)
        $gapEffective = $gapToLeader;
        if (!$allowed) {
            $gapEffective = min($gapEffective, $distToStopLine);
        }

        // Анти-коллизия с машинами, уже находящимися в узле и пришедшими
        // с того же входа (тот же arm и для W/E — та же поворотная/прямая
        // полоса). Лидер в узле = виртуальное препятствие за стоп-линией,
        // gap до него = distToStopLine + 1 (одна клетка узла «за линией»).
        // Это не даёт второму въехать в ту же траекторию узла.
        if ($allowed && $this->hasLeaderInJunction($machinesAfterLC, $car, $effectiveGoal)) {
            $gapEffective = min($gapEffective, $distToStopLine);
        }

        // NaSch + VDR + медленное ускорение
        // Свежевъехавшая машина пропускает VDR на первом шаге, чтобы не
        // застрять сразу при появлении.
        $skipVDR = !empty($car['justEntered']);

        [$v, $accelTimer] = $this->applyNaSchVDR(
            $car['speed'], $car['accelTimer'] ?? 0, $effectiveVMax, $gapEffective, $p, $skipVDR
        );

        $newPos = $pos + $v;

        // Машина пересекает стоп-линию — въезжает в узел.
        // Goal в момент въезда фиксируется по полосе.
        if ($newPos > $roadLength - 1 && $allowed) {
            return [
                'position'   => 0,
                'speed'      => max(1, $v),
                'inJunction' => true,
                'arm'        => $car['arm'],
                'dir'        => $car['dir'],
                'lane'       => $lane,
                'goal'       => $effectiveGoal,
                'waitedAt'   => null,
                'accelTimer' => $accelTimer,
                'justEntered'=> false,
                'junctionProgress' => 0.5,
            ];
        }

        return [
            'position'   => min($newPos, $roadLength - 1),
            'speed'      => $v,
            'lane'       => $lane,
            'goal'       => $effectiveGoal,  // фиксируем goal даже не пересекая стоп-линию,
            // чтобы он соответствовал текущей полосе
            'inJunction' => false,
            'accelTimer' => $accelTimer,
            'justEntered'=> false,
        ];
    }

    /**
     * Эффективный goal по полосе на W/E. На S — без изменений.
     *
     * Полоса жёстко диктует манёвр:
     *   LANE_THROUGH (правая) → прямо (W→E или E→W),
     *   LANE_TURN    (левая)  → на S.
     */
    private function effectiveGoalForLane(string $arm, int $lane, string $goal): string
    {
        if ($arm === self::ARM_S) return $goal;

        if ($lane === self::LANE_TURN) {
            return self::ARM_S;
        }
        // LANE_THROUGH
        return $arm === self::ARM_W ? self::ARM_E : self::ARM_W;
    }

    // ---------------------------------------------------------------
    // Планирование: машина на выездной полосе
    // ---------------------------------------------------------------

    private function planOutbound(array $car, array $grid, int $roadLength, int $vMax, float $p): array
    {
        $pos  = $car['position'];
        $arm  = $car['arm'];
        $lane = $car['lane'] ?? 0;

        $gap = $this->gapAheadOnLane($grid, $arm, self::DIR_OUT, $lane, $pos, $roadLength);

        // Свежевышедшая из узла машина (justEntered) пропускает VDR на первом шаге
        $skipVDR = !empty($car['justEntered']);

        [$v, $accelTimer] = $this->applyNaSchVDR(
            $car['speed'], $car['accelTimer'] ?? 0, $vMax, $gap, $p, $skipVDR
        );

        return [
            'position'   => $pos + $v,
            'speed'      => $v,
            'accelTimer' => $accelTimer,
            'justEntered'=> false,
        ];
    }

    // ---------------------------------------------------------------
    // Выход из узла на выездную полосу
    // ---------------------------------------------------------------

    private function planExitJunction(array $car, array $grid, array $exitWillBeFree = []): array
    {
        $targetArm  = $car['goal'];
        $targetLane = $this->pickExitLane($car['arm'], $targetArm, $car['lane'] ?? 0);

        $willBeFree = $exitWillBeFree[$targetArm][$targetLane]
            ?? !isset($grid[$targetArm][self::DIR_OUT][$targetLane][0]);

        if ($willBeFree) {
            return [
                'arm'         => $targetArm,
                'dir'         => self::DIR_OUT,
                'lane'        => $targetLane,
                'position'    => 0,
                'speed'       => 1,
                'inJunction'  => false,
                'accelTimer'  => 0,
                'justEntered' => true,
            ];
        }

        return [
            'inJunction'       => true,
            'arm'              => $car['arm'],
            'dir'              => $car['dir'],
            'lane'             => $car['lane'] ?? 0,
            'goal'             => $car['goal'],
            'speed'            => 0,
            'junctionProgress' => $car['junctionProgress'] ?? 0.5,
        ];
    }

    /**
     * Выбор полосы на DIR_OUT целевого плеча.
     *
     * lane=0 — правая по ходу (THROUGH, у внешнего бордюра).
     * lane=1 — левая (TURN, у разделительной).
     *
     * При выходе из узла машина встаёт в правую (lane=0) полосу
     * целевого плеча — это правостороннее движение и одновременно
     * обеспечивает совпадение Y при сквозном проезде через узел
     * (W IN THROUGH → E DIR_OUT THROUGH — обе у бордюра).
     */
    private function pickExitLane(string $fromArm, string $toArm, int $fromLane): int
    {
        if ($toArm === self::ARM_S) {
            return self::LANE_SINGLE;
        }
        return self::LANE_THROUGH;
    }

    // ---------------------------------------------------------------
    // Конфликты: несколько машин одновременно въезжают в узел
    // ---------------------------------------------------------------

    /**
     * Есть ли в узле машина, пришедшая с того же входа и идущая по
     * пересекающейся траектории. Используется как виртуальный лидер
     * для anti-collision: пока «свой» в узле — за стоп-линию не лезем.
     *
     * Совпадение трассы:
     *   - тот же arm источника;
     *   - для W/E — та же полоса (LANE_THROUGH или LANE_TURN), это
     *     гарантирует, что траектории совпадают (одна стрелочная секция);
     *   - для S — однополосное, достаточно arm.
     */
    private function hasLeaderInJunction(array $machines, array $car, string $effectiveGoal): bool
    {
        foreach ($machines as $other) {
            if (empty($other['inJunction'])) continue;
            if ($other['arm'] !== $car['arm']) continue;
            if ($car['arm'] !== self::ARM_S) {
                if (($other['lane'] ?? 0) !== ($car['lane'] ?? 0)) continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Ключ трассы внутри узла: arm источника + lane (для W/E).
     * Для S полоса не различается.
     */
    private function trackKey(string $arm, int $lane): string
    {
        return $arm === self::ARM_S ? $arm : ($arm . ':' . $lane);
    }

    /**
     * Карта занятых трасс узла: [trackKey => true] по машинам, у которых
     * inJunction=true.
     */
    private function findOccupiedTracks(array $machines): array
    {
        $occupied = [];
        foreach ($machines as $car) {
            if (empty($car['inJunction'])) continue;
            $key = $this->trackKey($car['arm'], $car['lane'] ?? 0);
            $occupied[$key] = true;
        }
        return $occupied;
    }

    private function resolveJunctionConflicts(array $intentions, array $machines): array
    {
        // Машины, которые в этот шаг хотят оказаться в узле
        $entering = [];
        foreach ($intentions as $id => $intent) {
            if (!empty($intent['inJunction'])) {
                // Только новый въезд (раньше не были в узле)
                if (empty($machines[$id]['inJunction'])) {
                    $entering[$id] = [
                        'arm'      => $intent['arm'],
                        'goal'     => $intent['goal'],
                        'waitedAt' => $machines[$id]['waitedAt'] ?? PHP_INT_MAX,
                        'id'       => $id,
                    ];
                }
            }
        }

        if (count($entering) <= 1 && empty($this->findOccupiedTracks($machines))) {
            return $intentions;
        }

        $blocked = [];

        // Конфликт 0: машина уже в узле на той же трассе (arm+lane для W/E,
        // arm для S). Новый въезд по этой трассе блокируем.
        $occupiedTracks = $this->findOccupiedTracks($machines);
        foreach ($entering as $id => $e) {
            $key = $this->trackKey($e['arm'], $machines[$id]['lane'] ?? 0);
            if (isset($occupiedTracks[$key])) {
                $blocked[$id] = true;
            }
        }

        // Конфликт 0b: одновременно входят несколько с одного arm+lane —
        // пропускаем только самого «первого» (наибольшая position на DIR_IN,
        // т.е. кто ближе к стоп-линии; при равенстве — меньший id).
        $byTrack = [];
        foreach ($entering as $id => $e) {
            if (isset($blocked[$id])) continue;
            $key = $this->trackKey($e['arm'], $machines[$id]['lane'] ?? 0);
            $byTrack[$key][] = $id;
        }
        foreach ($byTrack as $ids) {
            if (count($ids) <= 1) continue;
            usort($ids, function ($a, $b) use ($machines) {
                $pa = $machines[$a]['position'] ?? 0;
                $pb = $machines[$b]['position'] ?? 0;
                if ($pa !== $pb) return $pb <=> $pa; // ближе к стоп-линии = выше position
                return $a <=> $b;
            });
            array_shift($ids);
            foreach ($ids as $id) $blocked[$id] = true;
        }

        // Конфликт 1: две машины хотят на одно и то же выездное плечо (одна целевая клетка)
        $byGoal = [];
        foreach ($entering as $id => $e) {
            if (isset($blocked[$id])) continue;
            $byGoal[$e['goal']][] = $id;
        }

        foreach ($byGoal as $goal => $ids) {
            if (count($ids) > 1) {
                // Приоритет — кто дольше ждёт (меньший waitedAt), при равенстве — меньший id
                usort($ids, fn($a, $b) => [$entering[$a]['waitedAt'], $a] <=> [$entering[$b]['waitedAt'], $b]);
                $winner = array_shift($ids);
                foreach ($ids as $id) $blocked[$id] = true;
            }
        }

        // Конфликт 2: пересечение траекторий внутри узла.
        // В фазе sec одновременно могут хотеть: W→S, E→S, S→W, S→E.
        // Пересекающиеся пары:
        //   W→S × S→E   (левый поворот с запада идёт через юг, поток с юга на восток режет его)
        //   E→S × S→W   (симметрично)
        $conflictingPairs = [
            [self::ARM_W, self::ARM_S, self::ARM_S, self::ARM_E],
            [self::ARM_E, self::ARM_S, self::ARM_S, self::ARM_W],
        ];

        $ids = array_keys($entering);
        $n   = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $entering[$ids[$i]];
                $b = $entering[$ids[$j]];
                foreach ($conflictingPairs as [$a1, $g1, $a2, $g2]) {
                    $matchAB = ($a['arm'] === $a1 && $a['goal'] === $g1 && $b['arm'] === $a2 && $b['goal'] === $g2);
                    $matchBA = ($b['arm'] === $a1 && $b['goal'] === $g1 && $a['arm'] === $a2 && $a['goal'] === $g2);
                    if ($matchAB || $matchBA) {
                        // Тот, кто дольше ждёт, проезжает; второй стоит
                        if ($a['waitedAt'] <= $b['waitedAt']) {
                            $blocked[$ids[$j]] = true;
                        } else {
                            $blocked[$ids[$i]] = true;
                        }
                    }
                }
            }
        }

        // Применяем блокировку: машина остаётся на стоп-линии, не въезжает в узел
        foreach (array_keys($blocked) as $id) {
            $car = $machines[$id];
            $intentions[$id] = [
                'position'   => $car['position'],  // там где стояла
                'speed'      => 0,
                'inJunction' => false,
            ];
        }

        return $intentions;
    }

    private function resolveExitConflicts(array $intentions, array $machines): array
    {
        // Группируем намерения по целевой клетке (arm, lane, pos=0 на DIR_OUT).
        // Интерес — только машины, которые в этом шаге окажутся
        // на DIR_OUT pos=0 (выход из узла или новые машины).
        $targets = [];
        foreach ($intentions as $id => $intent) {
            $arm  = $intent['arm']  ?? $machines[$id]['arm'];
            $dir  = $intent['dir']  ?? $machines[$id]['dir'];
            $pos  = $intent['position']   ?? $machines[$id]['position'];
            $lane = $intent['lane']       ?? ($machines[$id]['lane'] ?? 0);
            $inJ  = $intent['inJunction'] ?? $machines[$id]['inJunction'];

            if (!$inJ && $dir === self::DIR_OUT && $pos === 0) {
                $targets[$arm][$lane][] = $id;
            }
        }

        foreach ($targets as $arm => $byLane) {
            foreach ($byLane as $lane => $ids) {
                if (count($ids) <= 1) continue;

                // Приоритет — машина, которая УЖЕ была в узле (она дольше ждёт).
                usort($ids, function ($a, $b) use ($machines) {
                    $aInJ = $machines[$a]['inJunction'] ? 0 : 1;
                    $bInJ = $machines[$b]['inJunction'] ? 0 : 1;
                    if ($aInJ !== $bInJ) return $aInJ <=> $bInJ;
                    return $a <=> $b;
                });

                $winner = array_shift($ids);
                foreach ($ids as $id) {
                    $car = $machines[$id];
                    if ($car['inJunction']) {
                        $intentions[$id] = [
                            'inJunction'       => true,
                            'arm'              => $car['arm'],
                            'dir'              => $car['dir'],
                            'lane'             => $car['lane'] ?? 0,
                            'goal'             => $car['goal'],
                            'speed'            => 0,
                            'junctionProgress' => $car['junctionProgress'] ?? 0.0,
                        ];
                    } else {
                        // Стоит на стоп-линии своего DIR_IN
                        $intentions[$id] = [
                            'position'   => $car['position'],
                            'lane'       => $car['lane'] ?? 0,
                            'speed'      => 0,
                            'inJunction' => false,
                        ];
                    }
                }
            }
        }

        return $intentions;
    }

    // ---------------------------------------------------------------
    // Хелперы
    // ---------------------------------------------------------------

    private function isTurning(string $arm, string $goal): bool
    {
        // Прямо: только W↔E. Всё остальное — поворот.
        if ($arm === self::ARM_W && $goal === self::ARM_E) return false;
        if ($arm === self::ARM_E && $goal === self::ARM_W) return false;
        return true;
    }

    // ---------------------------------------------------------------
    // Удаление машин, доехавших до конца выездной полосы
    // ---------------------------------------------------------------

    private function removeCars(array $state, int $roadLength): array
    {
        foreach ($state['machines'] as $id => $car) {
            if ($car['dir'] === self::DIR_OUT && $car['position'] >= $roadLength) {
                unset($state['machines'][$id]);
                $state['finished']++;
            }
        }
        return $state;
    }

    // ---------------------------------------------------------------
    // Снапшот для истории
    // ---------------------------------------------------------------

    private function makeSnapshot(array $state, int $step): array
    {
        return [
            'step'       => $step,
            'phase'      => $state['phase'],
            'phaseTimer' => $state['phaseTimer'],
            'machines'   => array_values($state['machines']),
            'queues'     => [
                self::ARM_W => count($state['queues'][self::ARM_W]),
                self::ARM_E => count($state['queues'][self::ARM_E]),
                self::ARM_S => count($state['queues'][self::ARM_S]),
            ],
            'spawned'    => $state['spawned'],
            'finished'   => $state['finished'],
        ];
    }
}
