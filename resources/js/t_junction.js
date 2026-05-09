import './bootstrap';
import Konva from 'konva';
import Chart from 'chart.js/auto';

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
    phaseMain:  '#22C55E',
    phaseSec:   '#F59E0B',
    phaseClear: '#EF4444',
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
let statisticsData    = null;
let currentStep   = 0;
let roadLength    = 50;
let isPlaying     = false;
let animationId   = null;
let lastFrameTime = 0;
let stepProgress  = 0;
const STEP_MS     = 1000;
let charts = {};

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

const JW = LANE_W * 4;  // ширина и высота узла (квадрат), а также ширина S-плеча
const OX = 0;
const OY = 0;

// Верх/низ горизонтальных плеч и верх вертикального S
const ARM_TOP    = OY - JW/2;
const ARM_BOTTOM = OY + JW/2;
const S_TOP      = OY + JW/2;
// S — той же ширины что W/E (JW), реальная проезжая часть — две
// центральные полосы, отделённые от обочин разделителем x=0.
const S_LEFT     = OX - JW/2;
const S_RIGHT    = OX + JW/2;

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
        width: JW, height: armLen,
        fill: COLOR.asphalt,
    }));

    // ─── Разметка W/E ───
    // Сплошная между встречными — через ВСЮ ширину (включая узел),
    // чтобы не было «заворотов» в углах узла.
    // Пунктиры между попутными — только вне узла.
    const dashOpts  = { stroke: COLOR.marking, strokeWidth: 1.5, dash: [10, 10], opacity: 0.6 };
    const solidOpts = { stroke: COLOR.marking, strokeWidth: 2.0, opacity: 0.85 };

    [
        { y: OY - LANE_W, opts: dashOpts }, // между OUT THROUGH и OUT TURN
        { y: OY + LANE_W, opts: dashOpts }, // между IN TURN и IN THROUGH
    ].forEach(({ y, opts }) => {
        roadLayer.add(new Konva.Line({
            points: [OX - JW/2 - armLen, y, OX - JW/2, y], ...opts,
        }));
        roadLayer.add(new Konva.Line({
            points: [OX + JW/2, y, OX + JW/2 + armLen, y], ...opts,
        }));
    });
    // Сплошная y=0 — на плечах, прерывается узлом.
    // Внутри узла — пунктир (как реальная разметка перекрёстка),
    // чтобы машины при поворотах визуально не пересекали сплошную.
    roadLayer.add(new Konva.Line({
        points: [OX - JW/2 - armLen, OY, OX - JW/2, OY], ...solidOpts,
    }));
    roadLayer.add(new Konva.Line({
        points: [OX + JW/2, OY, OX + JW/2 + armLen, OY], ...solidOpts,
    }));
    roadLayer.add(new Konva.Line({
        points: [OX - JW/2, OY, OX + JW/2, OY], ...dashOpts,
    }));

    // ─── Разметка S ───
    // Только одна сплошная между встречными направлениями.
    roadLayer.add(new Konva.Line({
        points: [OX, S_TOP, OX, S_TOP + armLen], ...solidOpts,
    }));

    // ─── Бордюры внешние ───
    const curbOpts = { stroke: COLOR.curb, strokeWidth: 2 };
    // W
    roadLayer.add(new Konva.Line({ points: [OX - JW/2 - armLen, ARM_TOP,    OX - JW/2, ARM_TOP    ], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX - JW/2 - armLen, ARM_BOTTOM, OX - JW/2, ARM_BOTTOM ], ...curbOpts }));
    // E
    roadLayer.add(new Konva.Line({ points: [OX + JW/2, ARM_TOP,    OX + JW/2 + armLen, ARM_TOP    ], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX + JW/2, ARM_BOTTOM, OX + JW/2 + armLen, ARM_BOTTOM ], ...curbOpts }));
    // S — ровно от низа узла до конца плеча, по обеим внешним сторонам
    roadLayer.add(new Konva.Line({ points: [S_LEFT,  S_TOP, S_LEFT,  S_TOP + armLen], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [S_RIGHT, S_TOP, S_RIGHT, S_TOP + armLen], ...curbOpts }));

    // ─── Бордюры узла ───
    // Сверху узла (W и E встречаются) — одна прямая поверх узла,
    // потому что выше асфальта нет.
    roadLayer.add(new Konva.Line({
        points: [OX - JW/2, ARM_TOP, OX + JW/2, ARM_TOP], ...curbOpts,
    }));
    // Снизу узла бордюра нет — узел открыт в сторону S по всей ширине.

    // Стоп-линии не рисуем — они образуют «уголки» со сплошной
    // продольной разметкой. Логически их роль выполняет позиция
    // машины: на position = roadLength-1 машина останавливается
    // перед узлом если фаза не разрешает её манёвр.

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
// Семантика lane:
//   на DIR_IN W/E:  lane=0 (THROUGH) — правая по ходу (у внешнего бордюра),
//                   lane=1 (TURN)    — левая по ходу (у разделительной y=0).
//   на DIR_OUT W/E: lane=0 — правая по ходу (у бордюра),
//                   lane=1 — левая (у разделительной).
// На S — единственная полоса (lane=0).
//
// Эта геометрия даёт совпадение Y при прямом проезде через узел:
// машина W→E с правой полосы W (нижняя на канвасе) выходит на правую
// полосу E (тоже нижняя) — проезд выглядит ровным.
//
// Замечание для диплома: разделение «полоса = манёвр» (TURN-полоса
// только для поворота на S) — модельное упрощение для разделения
// потоков. По ПДД правый поворот возможен и с правой (THROUGH) полосы,
// но в нашей модели каждая полоса жёстко связана со своей фазой
// светофора (стрелочные секции), что и требует искусственного
// разделения.
// ─────────────────────────────────────────────

function carXY(arm, dir, pos, L, lane = 0) {
    const half = LANE_W / 2;

    if (arm === 'W') {
        if (dir === 'in') {
            // едет вправо. По ПДД для W→E (направо относительно S):
            // правый поворот делается с правой (нижней по канвасу) полосы.
            // THROUGH (lane=0, прямой) — у разделительной (верхняя),
            // TURN    (lane=1, поворот на S) — у нижнего бордюра.
            const y = (lane === 1) ? OY + 3 * half : OY + half;
            return {
                x: OX - JW/2 - (L - 1 - pos) * CELL - half,
                y,
                angle: 0,
            };
        } else {
            // DIR_OUT, едет влево. Правая по ходу — у верхнего бордюра.
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
            // едет влево. По ПДД для E→S (левый поворот относительно
            // направления движения): с левой по ходу полосы.
            // У машины, едущей влево, левая по ходу = ближе к
            // разделительной y=0 (нижняя на канвасе).
            // THROUGH (lane=0) — у верхнего бордюра,
            // TURN    (lane=1) — у разделительной.
            const y = (lane === 1) ? OY - half : OY - 3 * half;
            return {
                x: OX + JW/2 + (L - 1 - pos) * CELL + half,
                y,
                angle: 180,
            };
        } else {
            // DIR_OUT, едет вправо. lane=0 — верхняя (у разделителя),
            // чтобы прямой W→E (приходящий с верхней полосы W IN) не
            // менял Y при проезде через узел.
            const y = (lane === 1) ? OY + 3 * half : OY + half;
            return {
                x: OX + JW/2 + pos * CELL + half,
                y,
                angle: 0,
            };
        }
    }

    // S-плечо. Полная ширина плеча = JW (4*LANE_W); реально по
    // одной полосе в каждую сторону шириной 2*LANE_W (от центра
    // до бордюра). Машина ставится по центру СВОЕЙ полосы — на
    // расстоянии LANE_W от разделителя x=0 и от внешнего бордюра.
    if (dir === 'in') {
        return {
            x: OX + LANE_W,                       // центр правой полосы
            y: S_TOP + (L - 1 - pos) * CELL + half,
            angle: 270,
        };
    } else {
        return {
            x: OX - LANE_W,                       // центр левой полосы
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
    let c;
    if (phase === 'main')       c = COLOR.phaseMain;
    else if (phase === 'sec')   c = COLOR.phaseSec;
    else                        c = COLOR.phaseClear; // 'clear' — all-red
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
    // Угол корпуса считаем по производной траектории (направление к
    // точке чуть-чуть в будущем), как в кольцевой двухполосной модели.
    // Это даёт реалистичный наклон при диагональном съезде на соседнюю
    // полосу.
    const a = carXY(carA.arm, carA.dir, carA.position, L, carA.lane ?? 0);
    const b = carXY(carB.arm, carB.dir, carB.position, L, carB.lane ?? 0);
    const x = a.x + (b.x - a.x) * t;
    const y = a.y + (b.y - a.y) * t;

    // Базовый «продольный» угол — линейная интерполяция a.angle/b.angle
    // (на одном плече они совпадают).
    let da = b.angle - a.angle;
    if (da > 180)  da -= 360;
    if (da < -180) da += 360;
    let angle = a.angle + da * t;

    // Поправка на перестроение: если у машины менялась полоса,
    // её движение идёт по диагонали. Корректируем угол по фактическому
    // направлению (a → b).
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    if (Math.abs(dy) > 0.5) {
        // Есть боковое смещение — берём угол по реальному вектору.
        const realAngle = Math.atan2(dy, dx) * 180 / Math.PI;
        // Смешиваем плавно: в начале/конце шага — продольный угол,
        // в середине — реальный диагональный. Это даёт «вильнул и
        // выровнялся», без рывков на стыках шагов.
        const blend = 4 * t * (1 - t); // 0 на концах, 1 в середине
        let dRA = realAngle - angle;
        if (dRA > 180)  dRA -= 360;
        if (dRA < -180) dRA += 360;
        // Ограничим максимальный наклон корпуса при перестроении (~20°),
        // чтобы машина не разворачивалась слишком резко.
        const MAX_TILT = 20;
        dRA = Math.max(-MAX_TILT, Math.min(MAX_TILT, dRA));
        angle += dRA * blend;
    }

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
    } else if (phase === 'sec') {
        el.className    = 'mt-3 p-3 rounded-xl border border-amber-300 bg-amber-50 text-center';
        label.className = 'text-lg font-bold text-amber-700 mt-1';
        label.textContent = '🟡 Второстепенная (S, повороты)';
    } else { // 'clear' — all-red intergreen
        el.className    = 'mt-3 p-3 rounded-xl border border-red-300 bg-red-50 text-center';
        label.className = 'text-lg font-bold text-red-700 mt-1';
        label.textContent = '🔴 Очистка узла (all-red)';
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
    const totalH = L * CELL     + JW + 60;
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
            statisticsData    = data.statistics || null;
            currentStep  = 0;
            stepProgress = 0;
            stopPlay();
            centerScene(roadLength);
            drawRoad(roadLength);
            drawStep(0);

            document.getElementById('btn-play').disabled = false;
            document.getElementById('btn-prev').disabled = false;
            document.getElementById('btn-next').disabled = false;
            const btnStat = document.getElementById('btn-statistics');
            if (btnStat) btnStat.disabled = !statisticsData;
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

// ─────────────────────────────────────────────
// Модалка статистики
// ─────────────────────────────────────────────

const modal          = document.getElementById('statistics-modal');
const modalBackdrop  = document.getElementById('modal-backdrop');
const modalClose     = document.getElementById('modal-close');
const cardsContainer = document.getElementById('cards-container');
const btnPrevCard    = document.getElementById('btn-prev-card');
const btnNextCard    = document.getElementById('btn-next-card');
const cardDots       = document.querySelectorAll('.card-dot');
const btnStatistics  = document.getElementById('btn-statistics');

let currentCardIndex = 0;
const TOTAL_CARDS = 8;

if (btnStatistics) {
    btnStatistics.addEventListener('click', () => {
        if (!statisticsData) return;
        modal.classList.remove('hidden');
        renderStatistics();
        renderCharts();
    });
}

function closeModal() {
    if (modal) modal.classList.add('hidden');
    Object.values(charts).forEach(c => { if (c) c.destroy(); });
    charts = {};
}

if (modalClose)    modalClose.addEventListener('click', closeModal);
if (modalBackdrop) modalBackdrop.addEventListener('click', closeModal);

document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) closeModal();
    if (modal && !modal.classList.contains('hidden')) {
        if (e.key === 'ArrowLeft')  goCard(currentCardIndex - 1);
        if (e.key === 'ArrowRight') goCard(currentCardIndex + 1);
    }
});

if (btnPrevCard) btnPrevCard.addEventListener('click', () => goCard(currentCardIndex - 1));
if (btnNextCard) btnNextCard.addEventListener('click', () => goCard(currentCardIndex + 1));

cardDots.forEach(dot => {
    dot.addEventListener('click', () => goCard(parseInt(dot.dataset.index, 10)));
});

function goCard(idx) {
    if (idx < 0 || idx >= TOTAL_CARDS) return;
    currentCardIndex = idx;
    if (cardsContainer) {
        cardsContainer.style.transform = `translateX(-${idx * 100}%)`;
    }
    cardDots.forEach((dot, i) => {
        dot.classList.toggle('bg-indigo-500', i === idx);
        dot.classList.toggle('bg-gray-300',   i !== idx);
    });
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function renderStatistics() {
    if (!statisticsData) return;
    const { summary, fundamentalDiagram } = statisticsData;

    setText('stat-avg-speed',  summary.avgSpeed.toFixed(2));
    setText('stat-congestion', (summary.avgCongestionRate * 100).toFixed(1) + '%');

    setText('stat-queue-w', summary.avgQueueW.toFixed(2));
    setText('stat-queue-e', summary.avgQueueE.toFixed(2));
    setText('stat-queue-s', summary.avgQueueS.toFixed(2));

    setText('stat-thr-main',   summary.throughputMain);
    setText('stat-thr-sec',    summary.throughputSec);
    setText('stat-thr-main-h', summary.throughputMainPerHour);
    setText('stat-thr-sec-h',  summary.throughputSecPerHour);

    setText('stat-wait-total', summary.avgWaitTotal.toFixed(1) + ' с');

    setText('stat-total-created', summary.totalCreated);
    setText('stat-total-exited',  summary.totalExited);
    setText('stat-final-system',  summary.finalInSystem);

    setText('stat-density',  fundamentalDiagram.density.toFixed(4));
    setText('stat-flow',     fundamentalDiagram.flow.toFixed(3));
    setText('stat-fd-speed', fundamentalDiagram.speed.toFixed(2));
}

function renderCharts() {
    if (!statisticsData) return;
    Object.values(charts).forEach(c => { if (c) c.destroy(); });
    charts = {};

    const { perStep, summary, manoeuvres, fundamentalDiagram } = statisticsData;
    const steps = perStep.speed.map((_, i) => i);

    // 1. Скорость
    drawLine('chart-speed', steps, [
        { label: 'v̄', data: perStep.speed, color: 'rgb(59,130,246)' },
    ], { yTitle: 'клеток/шаг' });

    // 2. Заторы
    drawLine('chart-congestion', steps, [
        { label: 'доля v=0', data: perStep.congestionRate, color: 'rgb(239,68,68)' },
    ], { yTitle: 'доля', yMax: 1 });

    // 3. Очереди по плечам
    drawLine('chart-queues', steps, [
        { label: 'W', data: perStep.queueW, color: 'rgb(234,179,8)' },
        { label: 'E', data: perStep.queueE, color: 'rgb(245,158,11)' },
        { label: 'S', data: perStep.queueS, color: 'rgb(249,115,22)' },
    ], { yTitle: 'машин' });

    // 4. Пропускная способность по фазам
    drawLine('chart-throughput', steps, [
        { label: 'MAIN', data: perStep.throughputMain, color: 'rgb(34,197,94)' },
        { label: 'SEC',  data: perStep.throughputSec,  color: 'rgb(16,185,129)' },
    ], { yTitle: 'пересечений (нараст.)' });

    // 5. Время ожидания (бары по плечам)
    drawBar('chart-wait',
        ['W', 'E', 'S', 'Среднее'],
        [summary.avgWaitW, summary.avgWaitE, summary.avgWaitS, summary.avgWaitTotal],
        ['rgb(168,85,247)', 'rgb(217,70,239)', 'rgb(236,72,153)', 'rgb(124,58,237)'],
        { yTitle: 'шагов = секунд' }
    );

    // 6. Баланс
    drawLine('chart-balance', steps, [
        { label: 'Создано', data: perStep.created,  color: 'rgb(20,184,166)' },
        { label: 'Выехало', data: perStep.exited,   color: 'rgb(6,182,212)'  },
        { label: 'В системе', data: perStep.inSystem, color: 'rgb(59,130,246)' },
    ], { yTitle: 'машин' });

    // 7. Манёвры
    const labels = ['W→E', 'W→S', 'E→W', 'E→S', 'S→W', 'S→E'];
    const values = labels.map(k => manoeuvres[k] || 0);
    drawBar('chart-manoeuvres', labels, values,
        ['rgb(217,70,239)', 'rgb(192,38,211)', 'rgb(168,85,247)',
            'rgb(147,51,234)', 'rgb(126,34,206)', 'rgb(107,33,168)'],
        { yTitle: 'машин' }
    );

    // 8. Фундаментальная диаграмма (точка + теоретический колокол)
    drawFundamental('chart-fundamental', fundamentalDiagram, summary.vMax);
}

function drawLine(canvasId, steps, series, opts = {}) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    charts[canvasId] = new Chart(ctx, {
        type: 'line',
        data: {
            labels: steps,
            datasets: series.map(s => ({
                label: s.label,
                data: s.data,
                borderColor: s.color,
                backgroundColor: s.color.replace('rgb', 'rgba').replace(')', ',0.10)'),
                borderWidth: 2,
                tension: 0.25,
                pointRadius: 0,
                pointHoverRadius: 4,
            })),
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            scales: {
                x: { title: { display: true, text: 'шаг' }, grid: { color: 'rgba(0,0,0,0.05)' } },
                y: {
                    title: { display: !!opts.yTitle, text: opts.yTitle || '' },
                    beginAtZero: true,
                    max: opts.yMax,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                },
            },
            plugins: { legend: { display: series.length > 1, position: 'bottom' } },
        },
    });
}

function drawBar(canvasId, labels, values, colors, opts = {}) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    charts[canvasId] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: colors,
                borderColor: colors,
                borderWidth: 1,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    title: { display: !!opts.yTitle, text: opts.yTitle || '' },
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.05)' },
                },
                x: { grid: { display: false } },
            },
            plugins: { legend: { display: false } },
        },
    });
}

function drawFundamental(canvasId, fd, vMax) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;

    // Теоретическая огибающая: J = ρ·v̄(ρ); упрощённо берём
    // J = min(ρ·vMax, (1-ρ)·vMax) — треугольная аппроксимация.
    const curve = [];
    for (let r = 0; r <= 1.001; r += 0.02) {
        curve.push({ x: r, y: Math.min(r * vMax, (1 - r) * vMax) });
    }

    charts[canvasId] = new Chart(ctx, {
        type: 'scatter',
        data: {
            datasets: [
                {
                    label: 'Теор. огибающая',
                    data: curve,
                    showLine: true,
                    borderColor: 'rgba(244,114,182,0.5)',
                    backgroundColor: 'transparent',
                    pointRadius: 0,
                    borderDash: [4, 4],
                },
                {
                    label: 'Текущая симуляция',
                    data: [{ x: fd.density, y: fd.flow }],
                    backgroundColor: 'rgb(225,29,72)',
                    pointRadius: 8,
                    pointHoverRadius: 10,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: { title: { display: true, text: 'плотность ρ' }, beginAtZero: true, max: 1 },
                y: { title: { display: true, text: 'поток J' },     beginAtZero: true },
            },
            plugins: { legend: { position: 'bottom' } },
        },
    });
}
