import './bootstrap';
import Konva from 'konva';

// ─────────────────────────────────────────────
// Геометрия и цвета
// ─────────────────────────────────────────────
const CELL   = 32;
const LANE_W = CELL;

const COLOR = {
    asphalt:   '#2D2D2D',
    junction:  '#1A1A1A',
    marking:   '#FFFFFF',
    curb:      '#555555',
    phaseMain: '#22C55E',
    phaseSec:  '#F59E0B',
    cars: [
        '#3B82F6','#EF4444','#10B981','#F59E0B',
        '#8B5CF6','#EC4899','#06B6D4','#F97316',
        '#6366F1','#14B8A6',
    ],
};
const carColor = id => COLOR.cars[id % COLOR.cars.length];

// ─────────────────────────────────────────────
// Состояние
// ─────────────────────────────────────────────
let simulationHistory = [];
let currentStep   = 0;
let roadLength    = 50;
let isPlaying     = false;
let animationId   = null;
let lastFrameTime = 0;
let stepProgress  = 0;
const STEP_MS     = 1000;

// ─────────────────────────────────────────────
// Konva
// ─────────────────────────────────────────────
const containerEl = document.getElementById('container');

const stage = new Konva.Stage({
    container: 'container',
    width:  containerEl.offsetWidth,
    height: containerEl.offsetHeight,
    draggable: true,
});

stage.on('wheel', e => {
    e.evt.preventDefault();
    const scaleBy  = 1.1;
    const oldScale = stage.scaleX();
    const pointer  = stage.getPointerPosition();
    const mousePointTo = {
        x: (pointer.x - stage.x()) / oldScale,
        y: (pointer.y - stage.y()) / oldScale,
    };
    const newScale = e.evt.deltaY > 0 ? oldScale / scaleBy : oldScale * scaleBy;
    stage.scale({ x: newScale, y: newScale });
    stage.position({
        x: pointer.x - mousePointTo.x * newScale,
        y: pointer.y - mousePointTo.y * newScale,
    });
});

const roadLayer = new Konva.Layer();
const layer     = new Konva.Layer();
stage.add(roadLayer);
stage.add(layer);

// ─────────────────────────────────────────────
// Геометрия T-перекрёстка (правостороннее движение)
//
// Узел — квадрат JW × JW (4×4 LANE_W), полностью заполняет высоту
// двухпроезжих плеч W/E. S-плечо стыкуется к нижней кромке узла.
//
// Расположение полос на W/E (от верхнего бордюра к нижнему):
//    y = -3·LW/2  : DIR_OUT lane THROUGH (правая по ходу «от узла»)
//    y = -1·LW/2  : DIR_OUT lane TURN    (левая по ходу «от узла»)
//   ───── y = 0 : сплошная между встречными ─────
//    y = +1·LW/2  : DIR_IN  lane TURN    (левая, для поворота на S)
//    y = +3·LW/2  : DIR_IN  lane THROUGH (правая, для прямого W↔E)
//
// S-плечо однополосное: x = -LW/2 — DIR_OUT, x = +LW/2 — DIR_IN.
// ─────────────────────────────────────────────

const JW = LANE_W * 4;  // ширина и высота узла (квадрат)
const OX = 0;
const OY = 0;

// Верх/низ горизонтальных плеч и верх вертикального S
const ARM_TOP    = OY - JW/2;
const ARM_BOTTOM = OY + JW/2;
const S_TOP      = OY + JW/2;   // S стыкуется к низу узла
const S_LEFT     = OX - LANE_W; // S — две полосы шириной 2*LANE_W
const S_RIGHT    = OX + LANE_W;

function drawRoad(L) {
    roadLayer.destroyChildren();

    const armLen = L * CELL;

    // Узел
    roadLayer.add(new Konva.Rect({
        x: OX - JW/2, y: ARM_TOP,
        width: JW, height: JW,
        fill: COLOR.junction,
    }));

    // Плечи (асфальт)
    roadLayer.add(new Konva.Rect({
        x: OX - JW/2 - armLen, y: ARM_TOP,
        width: armLen, height: JW,
        fill: COLOR.asphalt,
    }));
    roadLayer.add(new Konva.Rect({
        x: OX + JW/2, y: ARM_TOP,
        width: armLen, height: JW,
        fill: COLOR.asphalt,
    }));
    roadLayer.add(new Konva.Rect({
        x: S_LEFT, y: S_TOP,
        width: S_RIGHT - S_LEFT, height: armLen,
        fill: COLOR.asphalt,
    }));

    // ─── Разметка W/E (только вне узла) ───
    const dashOpts  = { stroke: COLOR.marking, strokeWidth: 1.5, dash: [10, 10], opacity: 0.6 };
    const solidOpts = { stroke: COLOR.marking, strokeWidth: 2.0, opacity: 0.85 };

    [
        { y: OY - LANE_W, opts: dashOpts  }, // между OUT THROUGH и OUT TURN
        { y: OY,          opts: solidOpts }, // сплошная между встречными
        { y: OY + LANE_W, opts: dashOpts  }, // между IN TURN и IN THROUGH
    ].forEach(({ y, opts }) => {
        roadLayer.add(new Konva.Line({
            points: [OX - JW/2 - armLen, y, OX - JW/2, y], ...opts,
        }));
        roadLayer.add(new Konva.Line({
            points: [OX + JW/2, y, OX + JW/2 + armLen, y], ...opts,
        }));
    });

    // S-плечо: сплошная между встречными
    roadLayer.add(new Konva.Line({
        points: [OX, S_TOP, OX, S_TOP + armLen], ...solidOpts,
    }));

    // ─── Бордюры (внешние границы плеч; внутри узла нет) ───
    const curbOpts = { stroke: COLOR.curb, strokeWidth: 2 };
    // W: верх и низ
    roadLayer.add(new Konva.Line({ points: [OX - JW/2 - armLen, ARM_TOP,    OX - JW/2, ARM_TOP    ], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX - JW/2 - armLen, ARM_BOTTOM, OX - JW/2, ARM_BOTTOM ], ...curbOpts }));
    // E: верх и низ
    roadLayer.add(new Konva.Line({ points: [OX + JW/2, ARM_TOP,    OX + JW/2 + armLen, ARM_TOP    ], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX + JW/2, ARM_BOTTOM, OX + JW/2 + armLen, ARM_BOTTOM ], ...curbOpts }));
    // S: левый и правый — рисуются ниже скруглений (см. угловые скругления)

    // ─── Бордюры узла ───
    // Сверху узла (там где W и E встречаются) — одна прямая поверх узла,
    // поскольку выше асфальта нет.
    roadLayer.add(new Konva.Line({
        points: [OX - JW/2, ARM_TOP, OX + JW/2, ARM_TOP], ...curbOpts,
    }));
    // Снизу узла — бордюр идёт от внешнего края W до левого края S и от
    // правого края S до внешнего края E (середина — стык с S, открыта).
    roadLayer.add(new Konva.Line({
        points: [OX - JW/2, ARM_BOTTOM, S_LEFT, ARM_BOTTOM], ...curbOpts,
    }));
    roadLayer.add(new Konva.Line({
        points: [S_RIGHT, ARM_BOTTOM, OX + JW/2, ARM_BOTTOM], ...curbOpts,
    }));
    // Бордюры S — на всю длину плеча
    roadLayer.add(new Konva.Line({
        points: [S_LEFT, ARM_BOTTOM, S_LEFT, S_TOP + armLen], ...curbOpts,
    }));
    roadLayer.add(new Konva.Line({
        points: [S_RIGHT, ARM_BOTTOM, S_RIGHT, S_TOP + armLen], ...curbOpts,
    }));

    // ─── Стоп-линии перед узлом ───
    const stopOpts = { stroke: COLOR.marking, strokeWidth: 3, opacity: 0.9 };
    // W IN — на x = -JW/2, по обеим IN-полосам (y от 0 до +JW/2)
    roadLayer.add(new Konva.Line({
        points: [OX - JW/2, OY, OX - JW/2, OY + JW/2], ...stopOpts,
    }));
    // E IN — на x = +JW/2, по обеим IN-полосам (y от -JW/2 до 0)
    roadLayer.add(new Konva.Line({
        points: [OX + JW/2, OY - JW/2, OX + JW/2, OY], ...stopOpts,
    }));
    // S IN — на y = S_TOP, по правой полосе (x от 0 до +LANE_W)
    roadLayer.add(new Konva.Line({
        points: [OX, S_TOP, S_RIGHT, S_TOP], ...stopOpts,
    }));

    // ─── Подписи плечей ───
    const labelOpts = { fontSize: 14, fontStyle: 'bold', fill: '#AAA' };
    roadLayer.add(new Konva.Text({ x: OX - JW/2 - armLen - 16, y: OY - 7, text: 'W', ...labelOpts }));
    roadLayer.add(new Konva.Text({ x: OX + JW/2 + armLen + 4,  y: OY - 7, text: 'E', ...labelOpts }));
    roadLayer.add(new Konva.Text({ x: OX - 6, y: S_TOP + armLen + 4, text: 'S', ...labelOpts }));

    roadLayer.draw();
}

// ─────────────────────────────────────────────
// Перевод (arm, dir, lane, position) → (x, y, angle)
// Правостороннее движение
//
// Для W/E: lane 0 — LANE_THROUGH (внешняя, у бордюра, для прямого
// проезда), lane 1 — LANE_TURN (внутренняя, у разделительной полосы,
// для поворота на S).
// На S — единственная полоса (lane 0).
// ─────────────────────────────────────────────

function carXY(arm, dir, pos, L, lane = 0) {
    const half = LANE_W / 2;

    if (arm === 'W') {
        // y разделительной = 0. Сверху DIR_OUT (-2..0), снизу DIR_IN (0..+2).
        if (dir === 'in') {
            // едет вправо. THROUGH (lane 0) у бордюра снизу, TURN (lane 1) у разделительной.
            const y = (lane === 1) ? OY + half : OY + 3 * half;
            return {
                x: OX - JW/2 - (L - 1 - pos) * CELL - half,
                y,
                angle: 0,
            };
        } else {
            // DIR_OUT, едет влево. THROUGH у бордюра сверху, TURN у разделительной.
            const y = (lane === 1) ? OY - half : OY - 3 * half;
            return {
                x: OX - JW/2 - pos * CELL - half,
                y,
                angle: 180,
            };
        }
    }

    if (arm === 'E') {
        if (dir === 'in') {
            // едет влево. THROUGH у бордюра сверху, TURN у разделительной.
            const y = (lane === 1) ? OY - half : OY - 3 * half;
            return {
                x: OX + JW/2 + (L - 1 - pos) * CELL + half,
                y,
                angle: 180,
            };
        } else {
            // DIR_OUT, едет вправо. THROUGH у бордюра снизу, TURN у разделительной.
            const y = (lane === 1) ? OY + half : OY + 3 * half;
            return {
                x: OX + JW/2 + pos * CELL + half,
                y,
                angle: 0,
            };
        }
    }

    // S-плечо (одна полоса в каждом направлении)
    if (dir === 'in') {
        // S→к узлу: едет вверх, правая полоса
        return {
            x: OX + half,
            y: S_TOP + (L - 1 - pos) * CELL + half,
            angle: 270,
        };
    } else {
        // S←от узла: едет вниз, левая полоса
        return {
            x: OX - half,
            y: S_TOP + pos * CELL + half,
            angle: 90,
        };
    }
}

// ─────────────────────────────────────────────
// Траектория через узел (квадратичная Безье)
//
// Машина едет от стоп-линии своего DIR_IN к началу целевого DIR_OUT.
// Линейная интерполяция давала диагональный «срез» через узел и
// визуальные наезды; кривая Безье с контрольной точкой на пересечении
// продолжений обеих полос даёт нормальный поворот.
// ─────────────────────────────────────────────

// Точка въезда в узел — конец стоп-линии своего DIR_IN на нужной полосе
function junctionEntry(arm, L, lane = 0) {
    return carXY(arm, 'in', L - 1, L, lane);
}

// Точка выхода из узла — начало целевого DIR_OUT.
// Машина выходит в правую (LANE_THROUGH = 0) полосу.
function junctionExit(goal, L, lane = 0) {
    return carXY(goal, 'out', 0, L, lane);
}

// Контрольная точка квадратичной Безье — пересечение касательных
// в точке въезда и точке выезда. Касательная — вдоль направления
// движения соответствующей полосы. Это даёт геометрически правильный
// поворот для любой комбинации (arm, goal, laneIn, laneOut).
//
// Для прямого проезда W↔E касательные параллельны → берём середину
// между точками въезда и выезда.
function junctionControl(arm, goal, L, laneIn = 0, laneOut = 0) {
    const entry = junctionEntry(arm, L, laneIn);
    const exit  = junctionExit(goal, L, laneOut);

    // Направления (вход «к узлу», выход «от узла»)
    const dirIn  = armInDirection(arm);   // куда едет машина на DIR_IN
    const dirOut = armOutDirection(goal); // куда едет на DIR_OUT

    // Параллельные направления — прямой проезд: контрольная — середина.
    if (Math.abs(dirIn.x * dirOut.y - dirIn.y * dirOut.x) < 1e-6) {
        return { x: (entry.x + exit.x) / 2, y: (entry.y + exit.y) / 2 };
    }

    // Пересечение прямых: entry + t1*dirIn = exit + t2*dirOut.
    // Решаем по компонентам.
    const det = dirIn.x * (-dirOut.y) - dirIn.y * (-dirOut.x);
    if (Math.abs(det) < 1e-6) {
        return { x: (entry.x + exit.x) / 2, y: (entry.y + exit.y) / 2 };
    }
    const dx = exit.x - entry.x;
    const dy = exit.y - entry.y;
    const t1 = (dx * (-dirOut.y) - dy * (-dirOut.x)) / det;
    return { x: entry.x + t1 * dirIn.x, y: entry.y + t1 * dirIn.y };
}

// Направление движения по DIR_IN на плече
function armInDirection(arm) {
    if (arm === 'W') return { x:  1, y: 0 }; // W→к узлу: вправо
    if (arm === 'E') return { x: -1, y: 0 }; // E→к узлу: влево
    return                  { x:  0, y: -1 }; // S→к узлу: вверх
}

// Направление движения по DIR_OUT с плеча
function armOutDirection(arm) {
    if (arm === 'W') return { x: -1, y: 0 }; // от узла к W: влево
    if (arm === 'E') return { x:  1, y: 0 }; // от узла к E: вправо
    return                  { x:  0, y: 1 }; // от узла к S: вниз
}

// Точка на квадратичной Безье в момент t ∈ [0,1]
function bezier(p0, p1, p2, t) {
    const u = 1 - t;
    return {
        x: u * u * p0.x + 2 * u * t * p1.x + t * t * p2.x,
        y: u * u * p0.y + 2 * u * t * p1.y + t * t * p2.y,
    };
}

// Производная Безье (для угла поворота кузова)
function bezierTangent(p0, p1, p2, t) {
    return {
        x: 2 * (1 - t) * (p1.x - p0.x) + 2 * t * (p2.x - p1.x),
        y: 2 * (1 - t) * (p1.y - p0.y) + 2 * t * (p2.y - p1.y),
    };
}

// Позиция машины внутри узла на момент t ∈ [0,1] её прохождения через узел.
// Когда снапшот единственный (статичный кадр) — берём середину t=0.5.
function junctionXY(car, L, t) {
    // laneIn — полоса въезда (берём из car.lane на момент въезда),
    // laneOut — полоса выезда. Машина выходит в LANE_THROUGH (правую) на W/E,
    // на S — единственная полоса.
    const laneIn  = car.lane ?? 0;
    const laneOut = (car.goal === 'S') ? 0 : 0; // на W/E DIR_OUT — LANE_THROUGH

    const p0 = junctionEntry(car.arm, L, laneIn);
    const p2 = junctionExit(car.goal, L, laneOut);
    const p1 = junctionControl(car.arm, car.goal, L, laneIn, laneOut);

    const tt = (t !== undefined) ? t
        : (car.junctionProgress !== undefined ? car.junctionProgress : 0.5);

    const pt   = bezier(p0, p1, p2, tt);
    const tan  = bezierTangent(p0, p1, p2, tt);
    const angle = Math.atan2(tan.y, tan.x) * 180 / Math.PI;
    return { x: pt.x, y: pt.y, angle };
}

// ─────────────────────────────────────────────
// Рисование одной машины
// ─────────────────────────────────────────────

function makeCar(x, y, angle, car) {
    const g = new Konva.Group({ x, y, rotation: angle });

    // Кузов
    g.add(new Konva.Rect({
        x: -CELL * 0.45, y: -CELL * 0.22,
        width:  CELL * 0.9, height: CELL * 0.44,
        fill: carColor(car.id),
        cornerRadius: 3,
        shadowColor: 'black', shadowBlur: 4,
        shadowOpacity: 0.4, shadowOffset: { x: 1, y: 1 },
    }));

    // Капот (блик)
    g.add(new Konva.Rect({
        x: CELL * 0.18, y: -CELL * 0.22,
        width: CELL * 0.27, height: CELL * 0.44,
        fill: 'rgba(255,255,255,0.25)',
        cornerRadius: [0, 3, 3, 0],
    }));

    // Индикатор скорости (хвост машины)
    g.add(new Konva.Circle({
        x: -CELL * 0.38, y: 0,
        radius: 3,
        fill: car.speed > 0 ? '#22C55E' : '#EF4444',
    }));

    // Номер машины — отдельным узлом, не вращается вместе с кузовом
    const label = new Konva.Text({
        text: String(car.id + 1),
        fontSize: 10,
        fontStyle: 'bold',
        fill: 'white',
    });
    // Центрируем якорь текста
    label.offsetX(label.width()  / 2);
    label.offsetY(label.height() / 2);
    // Компенсируем поворот группы — текст всегда читаемый
    label.rotation(-angle);
    g.add(label);

    return g;
}

// ─────────────────────────────────────────────
// Светофор (индикатор на узле)
// ─────────────────────────────────────────────

let trafficLight = null;
function drawTrafficLight(phase) {
    if (trafficLight) trafficLight.destroy();
    const c = phase === 'main' ? COLOR.phaseMain : COLOR.phaseSec;
    trafficLight = new Konva.Circle({
        x: OX, y: OY,
        radius: LANE_W * 0.8,
        fill: c, opacity: 0.3,
        shadowColor: c, shadowBlur: 18, shadowOpacity: 0.6,
    });
    layer.add(trafficLight);
}

// ─────────────────────────────────────────────
// Рендер шага (статика)
// ─────────────────────────────────────────────

function drawStep(stepIndex) {
    const snap = simulationHistory[stepIndex];
    if (!snap) return;

    // === DEBUG ===
    if (stepIndex >= 30 && stepIndex <= 60) {
        console.log(`step=${stepIndex}`, JSON.stringify(
            snap.machines.map(m => ({
                id: m.id,
                a: m.arm,
                d: m.dir,
                p: m.position,
                s: m.speed,
                j: m.inJunction ? 1 : 0
            }))
        ));
    }
    // === /DEBUG ===

    layer.destroyChildren();
    drawTrafficLight(snap.phase);

    snap.machines.forEach(car => {
        const { x, y, angle } = car.inJunction
            ? junctionXY(car, roadLength)
            : carXY(car.arm, car.dir, car.position, roadLength, car.lane ?? 0);
        layer.add(makeCar(x, y, angle, car));
    });

    layer.draw();
    updateUI(snap, stepIndex);
}

// ─────────────────────────────────────────────
// Интерполированный рендер
// ─────────────────────────────────────────────

function drawInterpolated(stepIndex, t) {
    const snapA = simulationHistory[stepIndex];
    const snapB = simulationHistory[stepIndex + 1];
    if (!snapA || !snapB) { drawStep(stepIndex); return; }

    // === DEBUG INTERP ===
    if (window._dbgCarId !== undefined && stepIndex !== window._dbgLastStep) {
        window._dbgLastStep = stepIndex;
        const a = snapA.machines.find(m => m.id === window._dbgCarId);
        const b = snapB.machines.find(m => m.id === window._dbgCarId);
        const fmt = m => m ? `${m.arm}/${m.dir}/${m.position} sp=${m.speed} j=${m.inJunction?1:0} pr=${m.junctionProgress??'-'}` : 'absent';
        console.log(`step ${stepIndex}->${stepIndex+1}  id=${window._dbgCarId}: ${fmt(a)}  →  ${fmt(b)}`);
    }
    // === /DEBUG INTERP ===

    const mapB = new Map(snapB.machines.map(m => [m.id, m]));

    layer.destroyChildren();
    drawTrafficLight(snapA.phase);

    snapA.machines.forEach(carA => {
        const carB = mapB.get(carA.id);
        if (!carB) return;

        const pos = positionAtT(carA, carB, t, roadLength);
        layer.add(makeCar(pos.x, pos.y, pos.angle, carA));
    });

    layer.draw();
    updateUI(snapA, stepIndex);
}

// ─────────────────────────────────────────────
// Главная диспетчеризация переходов A→B
//
// Внутри узла траектория — кривая Безье. Переходы DIR_IN→inJunction
// и inJunction→DIR_OUT — части той же кривой (первая половина и вторая
// половина). Это убирает «диагональный срез» через узел.
// ─────────────────────────────────────────────

function positionAtT(carA, carB, t, L) {
    const aIn = carA.inJunction;
    const bIn = carB.inJunction;

    // Обе в узле — машина застряла, рисуем статично в её прогрессе
    if (aIn && bIn) {
        const progress = carA.junctionProgress ?? 0.5;
        return junctionXY(carA, L, progress);
    }

    // Въезд в узел: первая половина кривой (0 → 0.5)
    if (!aIn && bIn) {
        const laneIn  = carA.lane ?? 0;
        const laneOut = 0; // на DIR_OUT всегда LANE_THROUGH
        const p0 = carXY(carA.arm, carA.dir, carA.position, L, laneIn);
        const p2 = junctionExit(carB.goal, L, laneOut);
        const p1 = junctionControl(carB.arm, carB.goal, L, laneIn, laneOut);
        const endProgress = carB.junctionProgress ?? 0.5;
        return bezierAt(p0, p1, p2, t * endProgress);
    }

    // Выезд из узла: вторая половина кривой (0.5 → 1.0)
    if (aIn && !bIn) {
        const laneIn  = carA.lane ?? 0;
        const laneOut = carB.lane ?? 0;
        const p0 = junctionEntry(carA.arm, L, laneIn);
        const p2 = carXY(carB.arm, carB.dir, carB.position, L, laneOut);
        const p1 = junctionControl(carA.arm, carA.goal, L, laneIn, laneOut);
        const startProgress = carA.junctionProgress ?? 0.5;
        return bezierAt(p0, p1, p2, startProgress + t * (1.0 - startProgress));
    }

    // Обе на полосах — учитываем возможное перестроение между шагами.
    // carA.lane → carB.lane интерполируется параллельно продольному
    // движению — машина «съезжает» по диагонали на соседнюю полосу.
    const a = carXY(carA.arm, carA.dir, carA.position, L, carA.lane ?? 0);
    const b = carXY(carB.arm, carB.dir, carB.position, L, carB.lane ?? 0);
    const x = a.x + (b.x - a.x) * t;
    const y = a.y + (b.y - a.y) * t;

    let da = b.angle - a.angle;
    if (da > 180)  da -= 360;
    if (da < -180) da += 360;
    const angle = a.angle + da * t;

    return { x, y, angle };
}

// Хелпер: позиция + угол на кривой Безье в момент t
function bezierAt(p0, p1, p2, t) {
    const pt  = bezier(p0, p1, p2, t);
    const tan = bezierTangent(p0, p1, p2, t);
    const angle = Math.atan2(tan.y, tan.x) * 180 / Math.PI;
    return { x: pt.x, y: pt.y, angle };
}

// ─────────────────────────────────────────────
// Обновление панелей
// ─────────────────────────────────────────────

function updateUI(snap, stepIndex) {
    document.getElementById('step-counter').textContent = stepIndex;
    updatePhaseIndicator(snap.phase);
    document.getElementById('q-W').textContent = snap.queues?.W ?? 0;
    document.getElementById('q-E').textContent = snap.queues?.E ?? 0;
    document.getElementById('q-S').textContent = snap.queues?.S ?? 0;
    document.getElementById('cnt-spawned').textContent  = snap.spawned;
    document.getElementById('cnt-finished').textContent = snap.finished;
}

function updatePhaseIndicator(phase) {
    const el    = document.getElementById('phase-indicator');
    const label = document.getElementById('phase-label');
    if (phase === 'main') {
        el.className    = 'mt-3 p-3 rounded-xl border border-green-300 bg-green-50 text-center';
        label.className = 'text-lg font-bold text-green-700 mt-1';
        label.textContent = '🟢 Главная (W ↔ E)';
    } else {
        el.className    = 'mt-3 p-3 rounded-xl border border-amber-300 bg-amber-50 text-center';
        label.className = 'text-lg font-bold text-amber-700 mt-1';
        label.textContent = '🟡 Второстепенная (S, повороты)';
    }
}

// ─────────────────────────────────────────────
// Игровой цикл
// ─────────────────────────────────────────────

function gameLoop(ts) {
    if (!isPlaying) return;
    if (!lastFrameTime) lastFrameTime = ts;
    const delta = ts - lastFrameTime;
    lastFrameTime = ts;
    stepProgress += delta / STEP_MS;

    if (stepProgress >= 1) {
        stepProgress = 0;
        currentStep++;
        if (currentStep >= simulationHistory.length - 1) {
            stopPlay();
            drawStep(simulationHistory.length - 1);
            return;
        }
    }
    drawInterpolated(currentStep, stepProgress);
    animationId = requestAnimationFrame(gameLoop);
}

function startPlay() {
    if (currentStep >= simulationHistory.length - 1) currentStep = 0;
    isPlaying     = true;
    lastFrameTime = 0;
    stepProgress  = 0;
    document.getElementById('icon-play').innerHTML = '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>';
    document.getElementById('text-play').textContent = 'Пауза';
    const btn = document.getElementById('btn-play');
    btn.classList.remove('from-emerald-500', 'to-green-500', 'hover:from-emerald-600', 'hover:to-green-600', 'hover:shadow-green-500/25', 'focus:ring-green-400');
    btn.classList.add('from-amber-500', 'to-yellow-500', 'hover:from-amber-600', 'hover:to-yellow-600', 'hover:shadow-amber-500/25', 'focus:ring-amber-400');
    animationId = requestAnimationFrame(gameLoop);
}

function stopPlay() {
    isPlaying = false;
    cancelAnimationFrame(animationId);
    document.getElementById('icon-play').innerHTML = '<path d="M8 5v14l11-7z"/>';
    document.getElementById('text-play').textContent = 'Воспроизведение';
    const btn = document.getElementById('btn-play');
    btn.classList.remove('from-amber-500', 'to-yellow-500', 'hover:from-amber-600', 'hover:to-yellow-600', 'hover:shadow-amber-500/25', 'focus:ring-amber-400');
    btn.classList.add('from-emerald-500', 'to-green-500', 'hover:from-emerald-600', 'hover:to-green-600', 'hover:shadow-green-500/25', 'focus:ring-green-400');
}

// ─────────────────────────────────────────────
// Центрирование
// ─────────────────────────────────────────────

function centerScene(L) {
    const w = containerEl.offsetWidth;
    const h = containerEl.offsetHeight;
    stage.width(w);
    stage.height(h);
    const totalW = L * CELL * 2 + JW + 60;
    const totalH = L * CELL     + JW + 60; // высота W/E плеч теперь JW
    const scale  = Math.min(w / totalW, h / totalH, 1);
    stage.scale({ x: scale, y: scale });
    stage.position({ x: w / 2, y: h / 2 });
}

// ─────────────────────────────────────────────
// Кнопки
// ─────────────────────────────────────────────

const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

document.getElementById('btn-load').addEventListener('click', () => {
    const payload = {
        mode:       'tjunction',
        roadLength: parseInt(document.getElementById('inp-roadLength').value, 10),
        iterations: parseInt(document.getElementById('inp-iterations').value, 10),
        vMax:       parseInt(document.getElementById('inp-vMax').value, 10),
        p:          parseFloat(document.getElementById('inp-p').value) || 0.3,
        tPhaseMain: parseInt(document.getElementById('inp-tPhaseMain').value, 10),
        tPhaseSec:  parseInt(document.getElementById('inp-tPhaseSec').value, 10),
        lambdaW:    parseFloat(document.getElementById('inp-lambdaW').value) || 0,
        lambdaE:    parseFloat(document.getElementById('inp-lambdaE').value) || 0,
        lambdaS:    parseFloat(document.getElementById('inp-lambdaS').value) || 0,
    };

    roadLength = payload.roadLength;

    fetch('/api/calculate', {
        method: 'POST',
        headers: {
            'Content-Type':  'application/json',
            'Accept':        'application/json',
            'X-CSRF-TOKEN':  csrfToken,
        },
        body: JSON.stringify(payload),
    })
        .then(r => r.json())
        .then(data => {
            simulationHistory = data.history;
            currentStep  = 0;
            stepProgress = 0;
            stopPlay();
            centerScene(roadLength);
            drawRoad(roadLength);
            drawStep(0);

            document.getElementById('btn-play').disabled = false;
            document.getElementById('btn-prev').disabled = false;
            document.getElementById('btn-next').disabled = false;
        })
        .catch(err => console.error('Ошибка:', err));
});

document.getElementById('btn-play').addEventListener('click', () => {
    if (isPlaying) stopPlay(); else startPlay();
});

document.getElementById('btn-prev').addEventListener('click', () => {
    stopPlay();
    if (currentStep > 0) { currentStep--; drawStep(currentStep); }
});

document.getElementById('btn-next').addEventListener('click', () => {
    stopPlay();
    if (currentStep < simulationHistory.length - 1) { currentStep++; drawStep(currentStep); }
});
