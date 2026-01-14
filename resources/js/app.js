import './bootstrap';
import Konva from 'konva';

// --- НАСТРОЙКИ ---
const cellsCount = 15;
const cellSize = 50;

// 1. Настройка сцены
const stage = new Konva.Stage({
    container: 'container',
    width: 800,
    height: 200,
});

const layer = new Konva.Layer();
stage.add(layer);

// 2. Рисуем сетку дороги (статичная, рисуем один раз)
for (let i = 0; i < cellsCount; i++) {
    const rect = new Konva.Rect({
        x: i * cellSize,
        y: 50,
        width: cellSize,
        height: cellSize,
        stroke: 'black',
        strokeWidth: 2,
    });
    layer.add(rect);
}

// Группа, в которой будут лежать наши машинки (чтобы легко их очищать)
const carsGroup = new Konva.Group();
layer.add(carsGroup);

// Наша модель данных (1 - машина, 0 - пусто)
let roadModel = [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]; 

// --- ФУНКЦИИ ---

// Функция отрисовки: смотрит в массив roadModel и рисует машинки
function drawState() {
    // 1. Удаляем старые машинки с экрана
    carsGroup.destroyChildren();

    // 2. Пробегаем по массиву
    roadModel.forEach((cellValue, index) => {
        if (cellValue === 1) {
            // Если тут машина, рисуем её
            const car = new Konva.Rect({
                x: index * cellSize + 5,
                y: 50 + 5,
                width: cellSize - 10,
                height: cellSize - 10,
                fill: 'red',
            });
            carsGroup.add(car);
        }
    });

    // 3. Обновляем слой (обязательно!)
    layer.draw();
}

// Функция шага симуляции (Логика)
function step() {
    // Создаем новый пустой массив нужной длины
    let newRoadModel = new Array(cellsCount).fill(0);

    // Пробегаем по старому массиву
    for (let i = 0; i < roadModel.length; i++) { // Исправлено: .length
        if (roadModel[i] === 1) {
            // Двигаем машину на 1 клетку вперед
            // Используем % cellsCount, чтобы сделать кольцо (тор)
            let nextPosition = (i + 1) % cellsCount; 
            newRoadModel[nextPosition] = 1;
        }
    }

    // Обновляем ГЛОБАЛЬНУЮ переменную
    roadModel = newRoadModel;
    
    console.log("Step:", roadModel);
}

// --- СОБЫТИЯ ---

// Первая отрисовка
drawState();

// Привязываем кнопку (убедись, что в HTML есть кнопка с id="btn-start")
const btn = document.getElementById('btn-start');
if (btn) {
    btn.addEventListener('click', () => {
        step();      // 1. Посчитать математику
        drawState(); // 2. Нарисовать результат
    });
}

console.log("Сцена загружена!");