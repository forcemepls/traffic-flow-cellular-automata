import './bootstrap';
import Konva from 'konva';

const container = document.getElementById('container');

// --- НАСТРОЙКИ СЦЕНЫ ---
// Начальные размеры (обновятся при нажатии кнопки)
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

// --- МАТЕМАТИКА ---
function getGeometry(roadLength) {
    let radius = (roadLength * fixedCellSize) / (2 * Math.PI);
    // Центр рисуем в 0,0, так как саму сцену мы сдвинем в центр экрана
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

// --- 1. ОТРИСОВКА СЕТКИ ---
function drawGrid(roadLength) {
    gridGroup.destroyChildren(); 
    
    const { radius, cx, cy } = getGeometry(roadLength);
    const angleStep = (2 * Math.PI) / roadLength; 

    for (let i = 0; i < roadLength; i++) {
        let angle = i * angleStep;
        let x = cx + radius * Math.cos(angle);
        let y = cy + radius * Math.sin(angle);

        let rect = new Konva.Rect({
            x: x,
            y: y,
            width: fixedCellSize,
            height: fixedCellSize,
            fill: 'white',
            stroke: '#ccc',
            strokeWidth: 1,
            offset: { x: fixedCellSize / 2, y: fixedCellSize / 2 },
            rotation: (angle * 180 / Math.PI) + 90,
        });

        // Рисуем цифры реже, если дорога длинная
        let textStep = roadLength > 50 ? 5 : 1;

        if (i % textStep === 0) {
            let textRadius = radius + fixedCellSize; 
            let tx = cx + textRadius * Math.cos(angle);
            let ty = cy + textRadius * Math.sin(angle);
            
            let text = new Konva.Text({
                x: tx,
                y: ty,
                text: i.toString(),
                fontSize: 12,
                fill: 'gray',
                offset: { x: 5, y: 5 }
            });
            gridGroup.add(text);
        }
        gridGroup.add(rect);
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
        
        let x = cx + radius * Math.cos(angle);
        let y = cy + radius * Math.sin(angle);

        let carRect = new Konva.Rect({
            x: x,
            y: y,
            width: fixedCellSize - 4,
            height: fixedCellSize - 8,
            fill: '#ef4444', 
            cornerRadius: 4,
            shadowBlur: 2,
            offset: { x: (fixedCellSize - 4) / 2, y: (fixedCellSize - 8) / 2 },
            rotation: (angle * 180 / Math.PI) + 90,
        });

        let carText = new Konva.Text({
            x: x,
            y: y,
            text: car.id,
            fontSize: 12,
            fontStyle: 'bold',
            fill: 'white',
            offset: { x: 3, y: 4 }, 
            rotation: (angle * 180 / Math.PI) + 90,
        });

        carsGroup.add(carRect);
        carsGroup.add(carText);
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
        const payload = {
            roadLength: parseInt(document.getElementById('inp-roadLength').value),
            numberCars: parseInt(document.getElementById('inp-numberCars').value),
            vMax: parseInt(document.getElementById('inp-vMax').value),
            iterations: parseInt(document.getElementById('inp-iterations').value),
        };
        currentRoadLength = payload.roadLength;

        // --- ВАЖНОЕ ИСПРАВЛЕНИЕ ЦЕНТРИРОВАНИЯ ---
        // 1. Получаем актуальные размеры контейнера
        const w = container.offsetWidth;
        const h = container.offsetHeight;
        
        // 2. Обновляем размер сцены
        stage.width(w);
        stage.height(h);
        
        // 3. Ставим позицию (0,0) сцены ровно в центр экрана
        stage.position({ x: w / 2, y: h / 2 });
        
        // 4. Сбрасываем зум на 1
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

            drawGrid(currentRoadLength);
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