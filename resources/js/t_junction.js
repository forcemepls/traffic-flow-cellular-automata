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
const STEP_MS     = 600;

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
// Расположение полос:
//   W-плечо: НИЖНЯЯ — DIR_IN  (W→к узлу, едет вправо)
//            ВЕРХНЯЯ — DIR_OUT (от узла на запад, едет влево)
//   E-плечо: ВЕРХНЯЯ — DIR_IN  (E→к узлу, едет влево)
//            НИЖНЯЯ — DIR_OUT (от узла на восток, едет вправо)
//   S-плечо: ПРАВАЯ  — DIR_IN  (S→к узлу, едет вверх)
//            ЛЕВАЯ   — DIR_OUT (от узла на юг, едет вниз)
//
// (Машины всегда держатся правее по ходу движения)
//
// Узел — квадрат 2×2 клетки (центр в (0,0))
// ─────────────────────────────────────────────

const JW = LANE_W * 2;  // ширина узла
const JH = LANE_W * 2;  // высота узла
const OX = 0;
const OY = 0;

function drawRoad(L) {
    roadLayer.destroyChildren();

    const armLen = L * CELL;

    // Узел
    roadLayer.add(new Konva.Rect({
        x: OX - JW/2, y: OY - JH/2,
        width: JW, height: JH,
        fill: COLOR.junction,
    }));

    // Плечи (асфальт)
    roadLayer.add(new Konva.Rect({
        x: OX - JW/2 - armLen, y: OY - JH/2,
        width: armLen, height: JH,
        fill: COLOR.asphalt,
    }));
    roadLayer.add(new Konva.Rect({
        x: OX + JW/2, y: OY - JH/2,
        width: armLen, height: JH,
        fill: COLOR.asphalt,
    }));
    roadLayer.add(new Konva.Rect({
        x: OX - JW/2, y: OY + JH/2,
        width: JW, height: armLen,
        fill: COLOR.asphalt,
    }));

    // Разметка — пунктир по центру каждого коридора
    const dashOpts = { stroke: COLOR.marking, strokeWidth: 1.5, dash: [10, 10], opacity: 0.5 };
    roadLayer.add(new Konva.Line({
        points: [OX - JW/2 - armLen, OY, OX - JW/2, OY], ...dashOpts,
    }));
    roadLayer.add(new Konva.Line({
        points: [OX + JW/2, OY, OX + JW/2 + armLen, OY], ...dashOpts,
    }));
    roadLayer.add(new Konva.Line({
        points: [OX, OY + JH/2, OX, OY + JH/2 + armLen], ...dashOpts,
    }));

    // Бордюры
    const curbOpts = { stroke: COLOR.curb, strokeWidth: 2 };
    roadLayer.add(new Konva.Line({ points: [OX - JW/2 - armLen, OY - JH/2, OX - JW/2, OY - JH/2], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX - JW/2 - armLen, OY + JH/2, OX - JW/2, OY + JH/2], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX + JW/2, OY - JH/2, OX + JW/2 + armLen, OY - JH/2], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX + JW/2, OY + JH/2, OX + JW/2 + armLen, OY + JH/2], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX - JW/2, OY + JH/2, OX - JW/2, OY + JH/2 + armLen], ...curbOpts }));
    roadLayer.add(new Konva.Line({ points: [OX + JW/2, OY + JH/2, OX + JW/2, OY + JH/2 + armLen], ...curbOpts }));

    // Подписи плечей
    const labelOpts = { fontSize: 14, fontStyle: 'bold', fill: '#AAA' };
    roadLayer.add(new Konva.Text({ x: OX - JW/2 - armLen - 16, y: OY - 8, text: 'W', ...labelOpts }));
    roadLayer.add(new Konva.Text({ x: OX + JW/2 + armLen + 4,  y: OY - 8, text: 'E', ...labelOpts }));
    roadLayer.add(new Konva.Text({ x: OX - 6, y: OY + JH/2 + armLen + 4, text: 'S', ...labelOpts }));

    roadLayer.draw();
}

// ─────────────────────────────────────────────
// Перевод (arm, dir, position) → (x, y, angle)
// Правостороннее движение
// ─────────────────────────────────────────────

function carXY(arm, dir, pos, L) {
    const half = LANE_W / 2;

    if (arm === 'W') {
        if (dir === 'in') {
            // W→к узлу: едет вправо, нижняя полоса
            return {
                x: OX - JW/2 - (L - 1 - pos) * CELL - half,
                y: OY + half,
                angle: 0,
            };
        } else {
            // W←от узла: едет влево, верхняя полоса
            return {
                x: OX - JW/2 - pos * CELL - half,
                y: OY - half,
                angle: 180,
            };
        }
    }

    if (arm === 'E') {
        if (dir === 'in') {
            // E→к узлу: едет влево, верхняя полоса
            return {
                x: OX + JW/2 + (L - 1 - pos) * CELL + half,
                y: OY - half,
                angle: 180,
            };
        } else {
            // E←от узла: едет вправо, нижняя полоса
            return {
                x: OX + JW/2 + pos * CELL + half,
                y: OY + half,
                angle: 0,
            };
        }
    }

    // S-плечо
    if (dir === 'in') {
        // S→к узлу: едет вверх, правая полоса
        return {
            x: OX + half,
            y: OY + JH/2 + (L - 1 - pos) * CELL + half,
            angle: 270,
        };
    } else {
        // S←от узла: едет вниз, левая полоса
        return {
            x: OX - half,
            y: OY + JH/2 + pos * CELL + half,
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

// Точка въезда в узел — конец стоп-линии своего DIR_IN
function junctionEntry(arm, L) {
    return carXY(arm, 'in', L - 1, L);
}

// Точка выхода из узла — начало целевого DIR_OUT
function junctionExit(goal, L) {
    return carXY(goal, 'out', 0, L);
}

// Контрольная точка кривой — пересечение «продолжения» полосы въезда
// и «продолжения» полосы выезда. Геометрически это и есть
// «угол поворота» рулевой траектории.
function junctionControl(arm, goal, L) {
    const half = LANE_W / 2;

    // Контрольные точки Безье. Для левых поворотов точка выносится
    // в дальний угол узла, чтобы дуга шла широко через центр —
    // иначе машина "срезает" поворот и пересекает встречные траектории.
    const CONTROL = {
        // Правые повороты — через ближний угол узла
        'W->S': { x: -half, y: +half },
        'S->E': { x: +half, y: +half },
        // Левые повороты — через дальний угол (диагональ от ближнего)
        'E->S': { x: -half, y: +half },
        'S->W': { x: +half, y: -half },
    };

    const key = `${arm}->${goal}`;
    if (CONTROL[key]) return CONTROL[key];

    const entry = junctionEntry(arm, L);
    const exit  = junctionExit(goal, L);
    return { x: (entry.x + exit.x) / 2, y: (entry.y + exit.y) / 2 };
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
    const p0 = junctionEntry(car.arm, L);
    const p2 = junctionExit(car.goal, L);
    const p1 = junctionControl(car.arm, car.goal, L);

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
        radius: LANE_W * 0.55,
        fill: c, opacity: 0.3,
        shadowColor: c, shadowBlur: 14, shadowOpacity: 0.6,
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
    const inJ   = snap.machines.filter(m => m.inJunction);
    const outZero = snap.machines.filter(m => !m.inJunction && m.dir === 'out' && m.position <= 2);
    if (inJ.length || outZero.length > 1) {
        console.log(`step=${stepIndex}`,
            'inJ:', JSON.stringify(inJ.map(m => ({id:m.id, arm:m.arm, goal:m.goal, p:m.junctionProgress, sp:m.speed}))),
            'outOI:', JSON.stringify(outZero.map(m => ({id:m.id, arm:m.arm, dir:m.dir, pos:m.position, sp:m.speed})))
        );
    }
    // === /DEBUG ===

    layer.destroyChildren();
    drawTrafficLight(snap.phase);

    snap.machines.forEach(car => {
        const { x, y, angle } = car.inJunction
            ? junctionXY(car, roadLength)
            : carXY(car.arm, car.dir, car.position, roadLength);
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
        const p0 = carXY(carA.arm, carA.dir, carA.position, L);
        const p2 = junctionExit(carB.goal, L);
        const p1 = junctionControl(carB.arm, carB.goal, L);
        const endProgress = carB.junctionProgress ?? 0.5;
        return bezierAt(p0, p1, p2, t * endProgress);
    }

    // Выезд из узла: вторая половина кривой (0.5 → 1.0)
    if (aIn && !bIn) {
        const p0 = junctionEntry(carA.arm, L);
        const p2 = carXY(carB.arm, carB.dir, carB.position, L);
        const p1 = junctionControl(carA.arm, carA.goal, L);
        const startProgress = carA.junctionProgress ?? 0.5;
        return bezierAt(p0, p1, p2, startProgress + t * (1.0 - startProgress));
    }

    // Обе на полосах
    const a = carXY(carA.arm, carA.dir, carA.position, L);
    const b = carXY(carB.arm, carB.dir, carB.position, L);
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
    const btn = document.getElementById('btn-play');
    btn.textContent = '⏸ Пауза';
    btn.classList.replace('bg-emerald-500', 'bg-amber-500');
    btn.classList.replace('hover:bg-emerald-600', 'hover:bg-amber-600');
    animationId = requestAnimationFrame(gameLoop);
}

function stopPlay() {
    isPlaying = false;
    cancelAnimationFrame(animationId);
    const btn = document.getElementById('btn-play');
    btn.textContent = '▶ Старт';
    btn.classList.replace('bg-amber-500', 'bg-emerald-500');
    btn.classList.replace('hover:bg-amber-600', 'hover:bg-emerald-600');
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
    const totalH = L * CELL     + JH + 60;
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
