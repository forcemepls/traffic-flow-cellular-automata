import './bootstrap';
import Konva from 'konva';

const container = document.getElementById('container');
const carImageObj = new Image();
carImageObj.src = '/images/car.png'; // Путь к файлу

// --- НАСТРОЙКИ СЦЕНЫ ---
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

// --- ПЕРЕМЕННЫЕ ---
const fixedCellSize = 30; 
let simulationHistory = [];
let currentStep = 0;
let playbackInterval = null;
let isPlaying = false;
let currentRoadLength = 0;
let isTwoLanesMode = false; // Флаг для режима

// --- МАТЕМАТИКА ---
function getGeometry(roadLength) {
    let radius = (roadLength * fixedCellSize) / (2 * Math.PI);
    return { radius, cx: 0, cy: 0 }; 
}

// --- ЗУМ ---
const scaleBy = 1.1;
stage.on('wheel', (e) => {
    e.evt.preventDefault();
    var oldScale = stage.scaleX();
    var pointer = stage.getPointerPosition();

    var mousePointTo = {
        x: (pointer.x - stage.x()) / oldScale,
        y: (pointer.y - stage.y()) / oldScale,
    };

    var newScale = e.evt.deltaY > 0 ? oldScale / scaleBy : oldScale * scaleBy;
    stage.scale({ x: newScale, y: newScale });

    var newPos = {
        x: pointer.x - mousePointTo.x * newScale,
        y: pointer.y - mousePointTo.y * newScale,
    };
    stage.position(newPos);
});

// --- 1. ОТРИСОВКА ДОРОГИ (АСФАЛЬТ + РАЗМЕТКА) ---
function drawGrid(roadLength, twoLanes) {
    gridGroup.destroyChildren(); 
    
    const { radius, cx, cy } = getGeometry(roadLength);
    
    // Параметры дороги
    const laneWidth = fixedCellSize * 1.5; // Ширина одной полосы (чуть больше машины)
    const lanesCount = twoLanes ? 2 : 1;
    
    // 1. Рисуем АСФАЛЬТ (Сплошное кольцо)
    // Внутренний радиус: Базовый радиус минус половина ширины полосы
    const innerRadius = radius - (laneWidth / 2);
    // Внешний радиус: Внутренний + (количество полос * ширина)
    const outerRadius = innerRadius + (lanesCount * laneWidth);

    const asphalt = new Konva.Ring({
        x: cx,
        y: cy,
        innerRadius: innerRadius,
        outerRadius: outerRadius,
        fill: '#333333', // Темно-серый асфальт
        stroke: '#555',  // Бордюр
        strokeWidth: 2,
        shadowBlur: 10,
        shadowColor: 'black',
        shadowOpacity: 0.3
    });
    gridGroup.add(asphalt);

    // 2. Рисуем ПУНКТИРНУЮ РАЗМЕТКУ (если 2 полосы)
    if (twoLanes) {
        // Линия проходит ровно между полосами
        const separatorRadius = radius + (laneWidth / 2) + 2; // +2 небольшая поправка

        const dashLine = new Konva.Circle({
            x: cx,
            y: cy,
            radius: separatorRadius,
            stroke: 'white',
            strokeWidth: 2,
            dash: [15, 15], // Длина штриха и пробела
            opacity: 0.8
        });
        gridGroup.add(dashLine);
    }

    // 3. (Опционально) Цифры километров/ячеек
    // Можно нарисовать их сбоку, чтобы не портить асфальт
    const angleStep = (2 * Math.PI) / roadLength; 
    
    // Рисуем метки каждые 10 клеток
    for (let i = 0; i < roadLength; i += 5) {
        let angle = i * angleStep;
        // Текст чуть внутри кольца
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

// --- 2. ОТРИСОВКА МАШИН ---
function drawStep(stepIndex) {
    if (!simulationHistory[stepIndex]) return;
    document.getElementById('step-counter').innerText = stepIndex;

    carsGroup.destroyChildren(); 
    
    const stepData = simulationHistory[stepIndex];
    const { radius, cx, cy } = getGeometry(currentRoadLength);
    const angleStep = (2 * Math.PI) / currentRoadLength;

    stepData.forEach(car => {
        let angle = car.position * angleStep;
        
        // Определяем полосу (0 или 1)
        let lane = car.lane || 0;
        
        // Считаем радиус для этой машины
        let currentRadius = radius + (lane * fixedCellSize * 1.5);

        let x = cx + currentRadius * Math.cos(angle);
        let y = cy + currentRadius * Math.sin(angle);

        let carRect = new Konva.Image({
            x: x,
            y: y,
            image: carImageObj, // Передаем объект картинки
            width: fixedCellSize, // Подгоняем размер под клетку
            height: fixedCellSize / 2, // Пропорции машины (обычно 2:1)
            
            // ОЧЕНЬ ВАЖНО: Центрируем точку вращения
            offset: { 
                x: fixedCellSize / 2, 
                y: (fixedCellSize / 2) / 2 
            },
            
            // Поворот (тот же, что был)
            rotation: (angle * 180 / Math.PI) + 90, 
        });

        carsGroup.add(carRect);
    });
    
    layer.draw();
}

// --- 3. ЛОГИКА ---
const btnLoad = document.getElementById('btn-load');
const btnPrev = document.getElementById('btn-prev');
const btnNext = document.getElementById('btn-next');
const btnPlay = document.getElementById('btn-play');

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
        // Проверяем, расширенная ли это модель (для отрисовки второй полосы)
        isTwoLanesMode = (modeValue === 'extendednagelschreckenberg');

        // Центрирование
        const w = container.offsetWidth;
        const h = container.offsetHeight;
        stage.width(w);
        stage.height(h);
        stage.position({ x: w / 2, y: h / 2 });
        stage.scale({ x: 1, y: 1 });

        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

        fetch('/simulation_nagel/calculate', {
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
            simulationHistory = data;
            currentStep = 0;
            stopAutoPlay(); 

            // Рисуем сетку (1 или 2 полосы)
            drawGrid(currentRoadLength, isTwoLanesMode);
            drawStep(0);
            
            btnPrev.disabled = false;
            btnNext.disabled = false;
            btnPlay.disabled = false;
        })
        .catch(error => console.error('Ошибка:', error));
    });
}

btnNext.addEventListener('click', () => {
    if (currentStep < simulationHistory.length - 1) {
        currentStep++;
        drawStep(currentStep);
    }
});

btnPrev.addEventListener('click', () => {
    if (currentStep > 0) {
        currentStep--;
        drawStep(currentStep);
    }
});

btnPlay.addEventListener('click', () => {
    if (isPlaying) stopAutoPlay();
    else startAutoPlay();
});

function startAutoPlay() {
    isPlaying = true;
    btnPlay.innerText = "⏸ Пауза";
    btnPlay.classList.replace('bg-green-500', 'bg-yellow-500'); 
    btnPlay.classList.replace('hover:bg-green-600', 'hover:bg-yellow-600');

    playbackInterval = setInterval(() => {
        if (currentStep >= simulationHistory.length - 1) {
            stopAutoPlay();
            return;
        }
        currentStep++;
        drawStep(currentStep);
    }, 500); 
}

function stopAutoPlay() {
    isPlaying = false;
    clearInterval(playbackInterval);
    btnPlay.innerText = "▶ Автовоспроизведение";
    btnPlay.classList.replace('bg-yellow-500', 'bg-green-500');
    btnPlay.classList.replace('hover:bg-yellow-600', 'hover:bg-green-600');
}


// --- ЛОГИКА БУРГЕР-МЕНЮ ---
const menuDrawer = document.getElementById('menu-drawer');
const menuBackdrop = document.getElementById('menu-backdrop');
const btnOpenMenu = document.getElementById('btn-open-menu');
const btnCloseMenu = document.getElementById('btn-close-menu');
const modelButtons = document.querySelectorAll('.model-select-btn');
const inputMode = document.getElementById('inp-mode');
const currentModelLabel = document.getElementById('current-model-name');

function openMenu() {
    menuBackdrop.classList.remove('hidden');
    menuDrawer.classList.remove('-translate-x-full');
}

function closeMenu() {
    menuBackdrop.classList.add('hidden');
    menuDrawer.classList.add('-translate-x-full');
}

if (btnOpenMenu) btnOpenMenu.addEventListener('click', openMenu);
if (btnCloseMenu) btnCloseMenu.addEventListener('click', closeMenu);
if (menuBackdrop) menuBackdrop.addEventListener('click', closeMenu);

// Выбор модели из списка
modelButtons.forEach(btn => {
    btn.addEventListener('click', () => {
        // 1. Получаем значение из data-value
        const value = btn.dataset.value;
        const name = btn.querySelector('h3').innerText;

        // 2. Обновляем скрытый инпут и заголовок
        inputMode.value = value;
        currentModelLabel.innerText = name;

        // 3. Выделяем активную кнопку (опционально, можно добавить стили)
        modelButtons.forEach(b => b.classList.remove('border-blue-500', 'bg-blue-50'));
        btn.classList.add('border-blue-500', 'bg-blue-50');

        // 4. Закрываем меню
        closeMenu();
        
        // 5. Опционально: можно сбрасывать сцену или сразу жать кнопку загрузки
        // btnLoad.click(); 
    });
});