<?php

namespace App\Services;

use App\Support\NaSchRulesTrait;

/**
 * T-образный перекрёсток со светофорным регулированием.
 *
 * Геометрия (правостороннее движение):
 *
 *                               W-плечо                E-плечо
 *   DIR_OUT (← от узла, к западу) ─── нижняя полоса ─── DIR_IN (← к узлу с востока)
 *   DIR_IN  (→ к узлу с запада)   ─── верхняя полоса ─── DIR_OUT (→ от узла, к востоку)
 *                                       │ узел │
 *                                       │      │
 *                            DIR_IN  ── │      │ ── DIR_OUT
 *                            (↑ к узлу) │      │ (↓ от узла)
 *                                       │      │
 *                                     S-плечо
 *
 * Светофор — 2 фазы:
 *   main: разрешает W→E, E→W
 *   sec : разрешает S→W, S→E, W→S, E→S
 *
 * Конфликты в узле во второстепенной фазе:
 *   W→S vs S→E   — пересекаются (W→S — левый поворот, S→E — выезд направо).
 *                  Приоритет — кто первым встал в очередь у узла.
 *   E→S vs S→W   — симметрично.
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

            // Не спавним, если въездная зона ещё занята предыдущей машиной
            if ($this->isSpawnZoneBusy($state['machines'], $arm)) continue;

            // Пуассоновский спавн: за шаг (1 секунду) вероятность p = λ/60
            $prob = min(1.0, $lambda / 60.0);
            if ((mt_rand() / mt_getrandmax()) >= $prob) continue;

            $goal = $this->pickGoal($arm);
            $state['queues'][$arm][] = $this->makeCar($state['nextId'], $arm, $goal);
            $state['nextId']++;
            $state['spawned']++;
        }
        return $state;
    }

    /**
     * Зона перед въездом занята, если в первых SPAWN_MIN_GAP клетках
     * DIR_IN уже стоит/едет машина. Это страхует от спавна "впритык"
     * и даёт лидеру разогнаться без помех от хвоста.
     */
    private function isSpawnZoneBusy(array $machines, string $arm): bool
    {
        foreach ($machines as $car) {
            if ($car['inJunction']) continue;
            if ($car['arm'] !== $arm) continue;
            if ($car['dir'] !== self::DIR_IN) continue;
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

    private function makeCar(int $id, string $arm, string $goal): array
    {
        return [
            'id'         => $id,
            'arm'        => $arm,
            'dir'        => self::DIR_IN,
            'position'   => 0,
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
            if ($this->isEntryOccupied($state['machines'], $arm)) continue;

            $car = array_shift($state['queues'][$arm]);
            $state['machines'][$car['id']] = $car;
        }
        return $state;
    }

    private function isEntryOccupied(array $machines, string $arm): bool
    {
        foreach ($machines as $car) {
            if ($car['arm'] === $arm
                && $car['dir'] === self::DIR_IN
                && !$car['inJunction']
                && $car['position'] === 0) {
                return true;
            }
        }
        return false;
    }

    // ---------------------------------------------------------------
    // Главный шаг: движение всех машин
    // ---------------------------------------------------------------

    private function moveCars(array $state, int $roadLength, int $vMax, float $p): array
    {
        $grid = $this->buildGrid($state['machines']);

        // Прогноз: какие клетки DIR_OUT[arm][0] будут СВОБОДНЫ после
        // движения машин, уже стоящих на этих клетках. Машина в узле
        // не должна ждать "лишний шаг" из-за того, что лидер на pos=0
        // уже планирует уехать в этом же шаге.
        $exitWillBeFree = [];
        foreach ([self::ARM_W, self::ARM_E, self::ARM_S] as $arm) {
            $exitWillBeFree[$arm] = !isset($grid[$arm][self::DIR_OUT][0]);
        }
        foreach ($state['machines'] as $car) {
            if ($car['inJunction']) continue;
            if ($car['dir'] !== self::DIR_OUT) continue;
            if ($car['position'] !== 0) continue;
            // Машина на pos=0 DIR_OUT. Если она хоть как-то двинется
            // в этот шаг (gap>0 и speed позволяет), клетка освободится.
            // Точный gap считать дорого — простое условие: впереди
            // нет машины на pos=1.
            $arm = $car['arm'];
            if (!isset($grid[$arm][self::DIR_OUT][1])) {
                $exitWillBeFree[$arm] = true;
            }
        }

        $intentions = [];

        foreach ($state['machines'] as $car) {
            if ($car['inJunction']) {
                $intentions[$car['id']] = $this->planExitJunction($car, $grid, $exitWillBeFree);
                continue;
            }
            if ($car['dir'] === self::DIR_IN) {
                $intentions[$car['id']] = $this->planInbound($car, $grid, $state['phase'], $roadLength, $vMax, $p);
            } else {
                $intentions[$car['id']] = $this->planOutbound($car, $grid, $roadLength, $vMax, $p);
            }
        }

        $intentions = $this->resolveJunctionConflicts($intentions, $state['machines']);
        $intentions = $this->resolveExitConflicts($intentions, $state['machines']);

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
     * grid[arm][dir][position] = carId
     */
    private function buildGrid(array $machines): array
    {
        $grid = [];
        foreach ($machines as $car) {
            if ($car['inJunction']) continue;
            $grid[$car['arm']][$car['dir']][$car['position']] = $car['id'];
        }
        return $grid;
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
        array $car, array $grid, string $phase, int $roadLength, int $vMax, float $p
    ): array {
        $pos            = $car['position'];
        $distToStopLine = ($roadLength - 1) - $pos;  // 0 = вплотную к узлу

        $allowed   = $this->isManoeuvreAllowed($car['arm'], $car['goal'], $phase);
        $isTurning = $this->isTurning($car['arm'], $car['goal']);

        // Anticipation: плавно тормозим, если впереди узел требует
        // снижения скорости — поворот или красный для нашего манёвра.
        // Сквозной зелёный — едем на vMax без ограничения.
        $effectiveVMax = $vMax;
        if ($isTurning || !$allowed) {
            $brakingCap    = max(1, (int) ceil($distToStopLine / self::BRAKE_RATE));
            $effectiveVMax = min($vMax, $brakingCap);
        }

        // gap до лидера на той же полосе
        $gapToLeader = $this->gapToLeaderInbound($grid, $car, $roadLength);

        // gap до стоп-линии (если манёвр запрещён — стоп-линия в позиции roadLength-1)
        $gapEffective = $gapToLeader;
        if (!$allowed) {
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

        // Машина пересекает стоп-линию — въезжает в узел
        if ($newPos > $roadLength - 1 && $allowed) {
            return [
                'position'   => 0,
                'speed'      => max(1, $v),
                'inJunction' => true,
                'arm'        => $car['arm'],
                'dir'        => $car['dir'],
                'goal'       => $car['goal'],
                'waitedAt'   => null,
                'accelTimer' => $accelTimer,
                'justEntered'=> false,
                'junctionProgress' => 0.5,
            ];
        }

        return [
            'position'   => min($newPos, $roadLength - 1),
            'speed'      => $v,
            'inJunction' => false,
            'accelTimer' => $accelTimer,
            'justEntered'=> false,
        ];
    }

    /**
     * Gap до ближайшей машины впереди на той же полосе.
     * Считаем сразу до стоп-линии: клетки за roadLength-1 — это узел, его трактуем
     * отдельно через junctionBlocked.
     */
    private function gapToLeaderInbound(array $grid, array $car, int $roadLength): int
    {
        $arm = $car['arm'];
        $pos = $car['position'];

        for ($i = 1; $i <= $roadLength; $i++) {
            $check = $pos + $i;
            if ($check >= $roadLength) {
                // Дошли до конца плеча — впереди узел.
                // Возвращаем "большой" gap, чтобы NaSch не ограничивал. Реальное
                // ограничение по узлу применяется отдельно через gapEffective.
                return $roadLength;
            }
            if (isset($grid[$arm][self::DIR_IN][$check])) {
                return $i - 1;
            }
        }
        return $roadLength;
    }

    // ---------------------------------------------------------------
    // Планирование: машина на выездной полосе
    // ---------------------------------------------------------------

    private function planOutbound(array $car, array $grid, int $roadLength, int $vMax, float $p): array
    {
        $pos = $car['position'];
        $arm = $car['arm'];

        $gap = $roadLength;
        for ($i = 1; $i <= $roadLength; $i++) {
            $check = $pos + $i;
            if ($check >= $roadLength) break;
            if (isset($grid[$arm][self::DIR_OUT][$check])) {
                $gap = $i - 1;
                break;
            }
        }

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
        $targetArm = $car['goal'];
        $willBeFree = $exitWillBeFree[$targetArm] ?? !isset($grid[$targetArm][self::DIR_OUT][0]);

        if ($willBeFree) {
            return [
                'arm'         => $targetArm,
                'dir'         => self::DIR_OUT,
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
            'goal'             => $car['goal'],
            'speed'            => 0,
            'junctionProgress' => $car['junctionProgress'] ?? 0.5,
        ];
    }

    // ---------------------------------------------------------------
    // Конфликты: несколько машин одновременно въезжают в узел
    // ---------------------------------------------------------------

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

        if (count($entering) <= 1) return $intentions;

        // Конфликт 1: две машины хотят на одно и то же выездное плечо (одна целевая клетка)
        $byGoal = [];
        foreach ($entering as $id => $e) {
            $byGoal[$e['goal']][] = $id;
        }

        $blocked = [];
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
        // Группируем намерения по целевой клетке (arm, dir, position).
        // Интерес — только машины, которые в этом шаге окажутся
        // на DIR_OUT pos=0 (выход из узла или новые машины).
        $targets = [];
        foreach ($intentions as $id => $intent) {
            $arm = $intent['arm'] ?? $machines[$id]['arm'];
            $dir = $intent['dir'] ?? $machines[$id]['dir'];
            $pos = $intent['position'] ?? $machines[$id]['position'];
            $inJ = $intent['inJunction'] ?? $machines[$id]['inJunction'];

            if (!$inJ && $dir === self::DIR_OUT && $pos === 0) {
                $targets[$arm][] = $id;
            }
        }

        foreach ($targets as $arm => $ids) {
            if (count($ids) <= 1) continue;

            // Приоритет — машина, которая УЖЕ была в узле (она дольше ждёт).
            // Если такая одна — она проезжает, остальные блокируются.
            // Если все одинаково (все из узла или все из очереди) — берём
            // меньший id для детерминированности.
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
                        'goal'             => $car['goal'],
                        'speed'            => 0,
                        'junctionProgress' => $car['junctionProgress'] ?? 0.0,
                    ];
                } else {
                    // Стоит на стоп-линии своего DIR_IN
                    $intentions[$id] = [
                        'position'   => $car['position'],
                        'speed'      => 0,
                        'inJunction' => false,
                    ];
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
