import './bootstrap';
import Konva from 'konva';
import Chart from 'chart.js/auto';

const container = document.getElementById('container');

const carImageObj = new Image();
carImageObj.src = '/images/car.png';

let stage = new Konva.Stage({
    container: 'container',
    width: container.offsetWidth,
    height: container.offsetHeight,
    draggable: true,
});

let layer = new Konva.Layer();
stage.add(layer);

let gridGroup = new Konva.Group();
let carsGroup = new Konva.Group();
layer.add(gridGroup);
layer.add(carsGroup);

const fixedCellSize = 30;
let simulationHistory = [];
let statisticsData = null;
let currentStep = 0;
let currentRoadLength = 0;
let isTwoLanesMode = false;

let isPlaying = false;
let animationRequestId = null;
let lastFrameTime = 0;
let currentProgress = 0;
const STEP_DURATION = 1000;

let currentCardIndex = 0;
const totalCards = 8;
let charts = {};

// Цвета для автомобилей (циклически повторяются)
const carColors = [
    '#3B82F6', // blue
    '#EF4444', // red
    '#10B981', // green
    '#F59E0B', // amber
    '#8B5CF6', // purple
    '#EC4899', // pink
    '#06B6D4', // cyan
    '#F97316', // orange
    '#6366F1', // indigo
    '#14B8A6', // teal
];

function getCarColor(carId) {
    return carColors[carId % carColors.length];
}

function getGeometry(roadLength) {
    let radius = (roadLength * fixedCellSize) / (2 * Math.PI);
    return { radius, cx: 0, cy: 0 };
}

const scaleBy = 1.1;
stage.on('wheel', (e) => {
    e.evt.preventDefault();
    let oldScale = stage.scaleX();
    let pointer = stage.getPointerPosition();
    let mousePointTo = {
        x: (pointer.x - stage.x()) / oldScale,
        y: (pointer.y - stage.y()) / oldScale,
    };
    let newScale = e.evt.deltaY > 0 ? oldScale / scaleBy : oldScale * scaleBy;
    stage.scale({ x: newScale, y: newScale });
    let newPos = {
        x: pointer.x - mousePointTo.x * newScale,
        y: pointer.y - mousePointTo.y * newScale,
    };
    stage.position(newPos);
});

function drawGrid(roadLength, twoLanes) {
    gridGroup.destroyChildren();
    const { radius, cx, cy } = getGeometry(roadLength);
    const laneWidth = fixedCellSize * 1.5;
    const lanesCount = twoLanes ? 2 : 1;
    const innerRadius = radius - (laneWidth / 2);
    const outerRadius = innerRadius + (lanesCount * laneWidth);

    const asphalt = new Konva.Ring({
        x: cx,
        y: cy,
        innerRadius: innerRadius,
        outerRadius: outerRadius,
        fill: '#333333',
        stroke: '#555',
        strokeWidth: 2,
        shadowBlur: 10,
        shadowColor: 'black',
        shadowOpacity: 0.3
    });
    gridGroup.add(asphalt);

    if (twoLanes) {
        const separatorRadius = radius + (laneWidth / 2) + 2;
        const dashLine = new Konva.Circle({
            x: cx,
            y: cy,
            radius: separatorRadius,
            stroke: 'white',
            strokeWidth: 2,
            dash: [15, 15],
            opacity: 0.8
        });
        gridGroup.add(dashLine);
    }

    const angleStep = (2 * Math.PI) / roadLength;
    for (let i = 0; i < roadLength; i += 5) {
        let angle = i * angleStep;
        let textRadius = innerRadius - 20;
        let tx = cx + textRadius * Math.cos(angle);
        let ty = cy + textRadius * Math.sin(angle);
        let text = new Konva.Text({
            x: tx,
            y: ty,
            text: i.toString(),
            fontSize: 10,
            fill: 'gray',
            offset: { x: 5, y: 5 },
            rotation: (angle * 180 / Math.PI) + 90,
        });
        gridGroup.add(text);
    }
}

function drawCarWithLabel(x, y, rotationAngle, carId, carSpeed) {
    const carGroup = new Konva.Group({
        x: x,
        y: y,
        rotation: rotationAngle,
    });

    // Основа автомобиля (прямоугольник с цветом)
    const carBody = new Konva.Rect({
        x: -fixedCellSize / 2,
        y: -fixedCellSize / 4,
        width: fixedCellSize,
        height: fixedCellSize / 2,
        fill: getCarColor(carId),
        cornerRadius: 4,
        shadowColor: 'black',
        shadowBlur: 5,
        shadowOpacity: 0.3,
        shadowOffset: { x: 2, y: 2 },
    });
    carGroup.add(carBody);

    // Белая полоса сверху (капот)
    const hood = new Konva.Rect({
        x: fixedCellSize / 6,
        y: -fixedCellSize / 4,
        width: fixedCellSize / 3,
        height: fixedCellSize / 2,
        fill: 'rgba(255,255,255,0.3)',
        cornerRadius: [0, 4, 4, 0],
    });
    carGroup.add(hood);

    // Номер автомобиля (круг с цифрой)
    const labelRadius = 10;
    const labelBg = new Konva.Circle({
        x: 0,
        y: -fixedCellSize / 2 - labelRadius - 2,
        radius: labelRadius,
        fill: 'white',
        stroke: getCarColor(carId),
        strokeWidth: 2,
        shadowColor: 'black',
        shadowBlur: 3,
        shadowOpacity: 0.2,
    });
    // Убираем вращение для метки, чтобы номер всегда был читаемым
    labelBg.rotation(-rotationAngle);
    carGroup.add(labelBg);

    const labelText = new Konva.Text({
        x: 0,
        y: -fixedCellSize / 2 - labelRadius - 2,
        text: String(carId + 1), // Нумерация с 1
        fontSize: 11,
        fontStyle: 'bold',
        fill: getCarColor(carId),
        align: 'center',
        verticalAlign: 'middle',
    });
    // Центрируем текст
    labelText.offsetX(labelText.width() / 2);
    labelText.offsetY(labelText.height() / 2);
    labelText.rotation(-rotationAngle);
    carGroup.add(labelText);

    // Индикатор скорости (маленькая полоска)
    if (carSpeed > 0) {
        const speedIndicator = new Konva.Rect({
            x: -fixedCellSize / 2 - 4,
            y: -2,
            width: 3,
            height: 4,
            fill: '#22C55E', // green
            cornerRadius: 1,
        });
        carGroup.add(speedIndicator);
    } else {
        // Красный индикатор если стоит
        const stopIndicator = new Konva.Rect({
            x: -fixedCellSize / 2 - 4,
            y: -2,
            width: 3,
            height: 4,
            fill: '#EF4444', // red
            cornerRadius: 1,
        });
        carGroup.add(stopIndicator);
    }

    return carGroup;
}

function drawInterpolated(stepIndex, progress) {
    if (!simulationHistory[stepIndex]) return;
    document.getElementById('step-counter').innerText = stepIndex;

    if (!simulationHistory[stepIndex + 1]) {
        drawStatic(stepIndex);
        return;
    }

    carsGroup.destroyChildren();

    const currentData = simulationHistory[stepIndex];
    const nextData = simulationHistory[stepIndex + 1];
    const nextDataMap = new Map(nextData.map(c => [c.id, c]));

    const { radius, cx, cy } = getGeometry(currentRoadLength);
    const angleStep = (2 * Math.PI) / currentRoadLength;

    currentData.forEach(car => {
        const nextCar = nextDataMap.get(car.id);

        if (nextCar) {
            let startPos = car.position;
            let endPos = nextCar.position;
            let delta = endPos - startPos;
            if (delta < 0) delta += currentRoadLength;

            let interpPos = startPos + (delta * progress);

            let startLane = car.lane || 0;
            let endLane = nextCar.lane || 0;
            let interpLane = startLane + (endLane - startLane) * progress;

            let angNow = interpPos * angleStep;
            let radNow = radius + (interpLane * fixedCellSize * 1.5);
            let xNow = cx + radNow * Math.cos(angNow);
            let yNow = cy + radNow * Math.sin(angNow);

            let pFuture = progress + 0.01;
            let iPosF = startPos + (delta * pFuture);
            let iLaneF = startLane + (endLane - startLane) * pFuture;

            let angF = iPosF * angleStep;
            let radF = radius + (iLaneF * fixedCellSize * 1.5);
            let xF = cx + radF * Math.cos(angF);
            let yF = cy + radF * Math.sin(angF);

            let rotationAngle;
            if (Math.abs(xF - xNow) > 0.001 || Math.abs(yF - yNow) > 0.001) {
                const dx = xF - xNow;
                const dy = yF - yNow;
                rotationAngle = Math.atan2(dy, dx) * 180 / Math.PI;
            } else {
                rotationAngle = (angNow * 180 / Math.PI) + 90;
            }

            // Интерполируем скорость для индикатора
            const currentSpeed = car.speed;

            const carNode = drawCarWithLabel(xNow, yNow, rotationAngle, car.id, currentSpeed);
            carsGroup.add(carNode);
        }
    });

    layer.draw();
}

function drawStatic(stepIndex) {
    if (!simulationHistory[stepIndex]) return;
    document.getElementById('step-counter').innerText = stepIndex;

    carsGroup.destroyChildren();

    const currentData = simulationHistory[stepIndex];
    const { radius, cx, cy } = getGeometry(currentRoadLength);
    const angleStep = (2 * Math.PI) / currentRoadLength;

    currentData.forEach(car => {
        let lane = car.lane || 0;
        let ang = car.position * angleStep;
        let rad = radius + (lane * fixedCellSize * 1.5);
        let x = cx + rad * Math.cos(ang);
        let y = cy + rad * Math.sin(ang);
        let rotationAngle = (ang * 180 / Math.PI) + 90;

        const carNode = drawCarWithLabel(x, y, rotationAngle, car.id, car.speed);
        carsGroup.add(carNode);
    });

    layer.draw();
}

function gameLoop(timestamp) {
    if (!isPlaying) return;
    if (!lastFrameTime) lastFrameTime = timestamp;

    const deltaTime = timestamp - lastFrameTime;
    lastFrameTime = timestamp;

    currentProgress += deltaTime / STEP_DURATION;

    if (currentProgress >= 1) {
        currentStep++;
        currentProgress = 0;

        if (currentStep >= simulationHistory.length - 1) {
            stopAutoPlay();
            drawStatic(simulationHistory.length - 1);
            return;
        }
    }

    drawInterpolated(currentStep, currentProgress);
    animationRequestId = requestAnimationFrame(gameLoop);
}

const btnLoad = document.getElementById('btn-load');
const btnPrev = document.getElementById('btn-prev');
const btnNext = document.getElementById('btn-next');
const btnPlay = document.getElementById('btn-play');
const btnStatistics = document.getElementById('btn-statistics');

if (btnLoad) {
    btnLoad.addEventListener('click', () => {
        const modeValue = document.getElementById('inp-mode').value;
        const payload = {
            mode: modeValue,
            roadLength: parseInt(document.getElementById('inp-roadLength').value),
            numberCars: parseInt(document.getElementById('inp-numberCars').value),
            vMax: parseInt(document.getElementById('inp-vMax').value),
            iterations: parseInt(document.getElementById('inp-iterations').value),
        };
        currentRoadLength = payload.roadLength;
        isTwoLanesMode = (modeValue === 'extendednagelschreckenberg');

        const w = container.offsetWidth;
        const h = container.offsetHeight;
        stage.width(w);
        stage.height(h);
        stage.position({ x: w / 2, y: h / 2 });
        stage.scale({ x: 1, y: 1 });

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        fetch('/api/calculate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            },
            body: JSON.stringify(payload)
        })
            .then(response => response.json())
            .then(data => {
                simulationHistory = data.history;
                statisticsData = data.statistics;

                currentStep = 0;
                currentProgress = 0;
                stopAutoPlay();

                drawGrid(currentRoadLength, isTwoLanesMode);
                drawStatic(0);

                btnPrev.disabled = false;
                btnNext.disabled = false;
                btnPlay.disabled = false;
                btnStatistics.disabled = false;
            })
            .catch(error => console.error('Ошибка:', error));
    });
}

btnNext.addEventListener('click', () => {
    if (currentStep < simulationHistory.length - 1) {
        currentStep++;
        drawStatic(currentStep);
    }
});

btnPrev.addEventListener('click', () => {
    if (currentStep > 0) {
        currentStep--;
        drawStatic(currentStep);
    }
});

btnPlay.addEventListener('click', () => {
    if (isPlaying) stopAutoPlay();
    else startAutoPlay();
});

function startAutoPlay() {
    if (currentStep >= simulationHistory.length - 1) {
        currentStep = 0;
    }

    isPlaying = true;

    document.getElementById('icon-play').innerHTML = '<path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>';
    document.getElementById('text-play').innerText = 'Пауза';

    btnPlay.classList.remove('from-emerald-500', 'to-green-500', 'hover:from-emerald-600', 'hover:to-green-600', 'hover:shadow-green-500/25', 'focus:ring-green-400');
    btnPlay.classList.add('from-amber-500', 'to-yellow-500', 'hover:from-amber-600', 'hover:to-yellow-600', 'hover:shadow-amber-500/25', 'focus:ring-amber-400');

    lastFrameTime = 0;
    currentProgress = 0;
    animationRequestId = requestAnimationFrame(gameLoop);
}

function stopAutoPlay() {
    isPlaying = false;
    cancelAnimationFrame(animationRequestId);

    document.getElementById('icon-play').innerHTML = '<path d="M8 5v14l11-7z"/>';
    document.getElementById('text-play').innerText = 'Воспроизведение';

    btnPlay.classList.remove('from-amber-500', 'to-yellow-500', 'hover:from-amber-600', 'hover:to-yellow-600', 'hover:shadow-amber-500/25', 'focus:ring-amber-400');
    btnPlay.classList.add('from-emerald-500', 'to-green-500', 'hover:from-emerald-600', 'hover:to-green-600', 'hover:shadow-green-500/25', 'focus:ring-green-400');

    drawStatic(currentStep);
}

// === МОДАЛЬНОЕ ОКНО СТАТИСТИКИ ===

const modal = document.getElementById('statistics-modal');
const modalBackdrop = document.getElementById('modal-backdrop');
const modalClose = document.getElementById('modal-close');
const cardsContainer = document.getElementById('cards-container');
const btnPrevCard = document.getElementById('btn-prev-card');
const btnNextCard = document.getElementById('btn-next-card');
const cardDots = document.querySelectorAll('.card-dot');

if (btnStatistics) {
    btnStatistics.addEventListener('click', () => {
        if (!statisticsData) return;

        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';

        currentCardIndex = 0;
        updateCarousel();
        renderAllCharts();
        populateStatistics();
    });
}

function closeModal() {
    modal.classList.add('hidden');
    document.body.style.overflow = '';
    destroyAllCharts();
}

if (modalClose) {
    modalClose.addEventListener('click', closeModal);
}

if (modalBackdrop) {
    modalBackdrop.addEventListener('click', closeModal);
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
        closeModal();
    }
});

if (btnPrevCard) {
    btnPrevCard.addEventListener('click', () => {
        if (currentCardIndex > 0) {
            currentCardIndex--;
            updateCarousel();
        }
    });
}

if (btnNextCard) {
    btnNextCard.addEventListener('click', () => {
        if (currentCardIndex < totalCards - 1) {
            currentCardIndex++;
            updateCarousel();
        }
    });
}

cardDots.forEach(dot => {
    dot.addEventListener('click', () => {
        currentCardIndex = parseInt(dot.dataset.index);
        updateCarousel();
    });
});

function updateCarousel() {
    if (cardsContainer) {
        cardsContainer.style.transform = `translateX(-${currentCardIndex * 100}%)`;
    }

    cardDots.forEach((dot, index) => {
        if (index === currentCardIndex) {
            dot.classList.remove('bg-gray-300', 'hover:bg-gray-400');
            dot.classList.add('bg-indigo-500');
        } else {
            dot.classList.remove('bg-indigo-500');
            dot.classList.add('bg-gray-300', 'hover:bg-gray-400');
        }
    });

    if (btnPrevCard) btnPrevCard.disabled = currentCardIndex === 0;
    if (btnNextCard) btnNextCard.disabled = currentCardIndex === totalCards - 1;
}

function destroyAllCharts() {
    Object.values(charts).forEach(chart => {
        if (chart) chart.destroy();
    });
    charts = {};
}

function populateStatistics() {
    if (!statisticsData) return;

    const { summary, travelTimes, overtakeEfficiency, fundamentalDiagram, meta } = statisticsData;

    const setTextContent = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };

    setTextContent('stat-avg-speed', summary.avgSpeed.toFixed(2));
    setTextContent('stat-congestion', (summary.avgCongestionRate * 100).toFixed(1) + '%');
    setTextContent('stat-braking', (summary.avgBrakingIndex * 100).toFixed(1) + '%');
    setTextContent('stat-gap', summary.avgGap.toFixed(1));
    setTextContent('stat-lane-changes', summary.totalLaneChanges);

    const effPercent = (overtakeEfficiency * 100).toFixed(1);
    setTextContent('stat-overtake', (overtakeEfficiency >= 0 ? '+' : '') + effPercent + '%');

    const idealTime = (meta.roadLength / summary.vMax).toFixed(1);
    setTextContent('stat-ideal-time', idealTime + ' шагов');

    const completedCount = travelTimes.filter(t => t.completed).length;
    setTextContent('stat-completed-laps', completedCount + ' / ' + meta.numCars);

    setTextContent('stat-density', fundamentalDiagram.density.toFixed(3));
    setTextContent('stat-flow', fundamentalDiagram.flow.toFixed(3));
    setTextContent('stat-fd-speed', fundamentalDiagram.speed.toFixed(2));
}

function renderAllCharts() {
    if (!statisticsData) return;

    destroyAllCharts();

    const { perStep, travelTimes, fundamentalDiagram } = statisticsData;
    const labels = perStep.speed.map((_, i) => i);

    // График 1: Средняя скорость
    const ctx1 = document.getElementById('chart-speed');
    if (ctx1) {
        charts.speed = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Средняя скорость',
                    data: perStep.speed,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { title: { display: true, text: 'Шаг симуляции' } },
                    y: { title: { display: true, text: 'Скорость (клеток/шаг)' }, beginAtZero: true }
                }
            }
        });
    }

    // График 2: Коэффициент заторов
    const ctx2 = document.getElementById('chart-congestion');
    if (ctx2) {
        charts.congestion = new Chart(ctx2, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Коэффициент заторов',
                    data: perStep.congestionRate.map(v => v * 100),
                    borderColor: 'rgb(239, 68, 68)',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { title: { display: true, text: 'Шаг симуляции' } },
                    y: { title: { display: true, text: 'Заторы (%)' }, beginAtZero: true, max: 100 }
                }
            }
        });
    }

    // График 3: Индекс замедлений
    const ctx3 = document.getElementById('chart-braking');
    if (ctx3) {
        charts.braking = new Chart(ctx3, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Индекс замедлений',
                    data: perStep.brakingIndex.map(v => v * 100),
                    borderColor: 'rgb(245, 158, 11)',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { title: { display: true, text: 'Шаг симуляции' } },
                    y: { title: { display: true, text: 'Замедления (%)' }, beginAtZero: true, max: 100 }
                }
            }
        });
    }

    // График 4: Средний Gap
    const ctx4 = document.getElementById('chart-gap');
    if (ctx4) {
        charts.gap = new Chart(ctx4, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Средний Gap',
                    data: perStep.avgGap,
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    fill: true,
                    tension: 0.3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { title: { display: true, text: 'Шаг симуляции' } },
                    y: { title: { display: true, text: 'Gap (клеток)' }, beginAtZero: true }
                }
            }
        });
    }

    // График 5: Интенсивность перестроений
    const ctx5 = document.getElementById('chart-lane-changes');
    if (ctx5) {
        charts.laneChanges = new Chart(ctx5, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Перестроения',
                    data: perStep.laneChangeRate.map(v => v * 100),
                    backgroundColor: 'rgba(168, 85, 247, 0.7)',
                    borderColor: 'rgb(168, 85, 247)',
                    borderWidth: 1,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { title: { display: true, text: 'Шаг симуляции' } },
                    y: { title: { display: true, text: 'Перестроения (%)' }, beginAtZero: true }
                }
            }
        });
    }

    // График 7: Время в пути (с цветами машин)
    const ctx7 = document.getElementById('chart-travel-time');
    if (ctx7) {
        const completedCars = travelTimes.filter(t => t.completed);
        const carLabels = completedCars.map(t => 'Авто ' + (t.id + 1)); // Нумерация с 1
        const carTimes = completedCars.map(t => t.lapTime);
        const carBgColors = completedCars.map(t => {
            const color = carColors[t.id % carColors.length];
            return color + 'B3'; // Добавляем прозрачность
        });
        const carBorderColors = completedCars.map(t => carColors[t.id % carColors.length]);

        charts.travelTime = new Chart(ctx7, {
            type: 'bar',
            data: {
                labels: carLabels,
                datasets: [{
                    label: 'Время (шаги)',
                    data: carTimes,
                    backgroundColor: carBgColors,
                    borderColor: carBorderColors,
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { title: { display: true, text: 'Автомобиль' } },
                    y: { title: { display: true, text: 'Время (шагов)' }, beginAtZero: true }
                }
            }
        });
    }

    // График 8: Фундаментальная диаграмма
    const ctx8 = document.getElementById('chart-fundamental');
    if (ctx8) {
        charts.fundamental = new Chart(ctx8, {
            type: 'scatter',
            data: {
                datasets: [{
                    label: 'Текущая симуляция',
                    data: [{ x: fundamentalDiagram.density, y: fundamentalDiagram.flow }],
                    backgroundColor: 'rgb(244, 63, 94)',
                    borderColor: 'rgb(244, 63, 94)',
                    pointRadius: 12,
                    pointHoverRadius: 15,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: true, position: 'top' }
                },
                scales: {
                    x: {
                        title: { display: true, text: 'Плотность (ρ)' },
                        min: 0,
                        max: 1,
                    },
                    y: {
                        title: { display: true, text: 'Поток (J)' },
                        beginAtZero: true,
                    }
                }
            }
        });
    }
}
