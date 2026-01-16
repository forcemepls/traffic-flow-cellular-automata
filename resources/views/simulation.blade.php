<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Модель Нагеля-Шрекенберга</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 p-4 font-sans h-screen flex flex-col">

    <div class="flex gap-4 h-full">
        
        <!-- ЛЕВАЯ КОЛОНКА: Настройки и Управление -->
        <div class="w-1/4 min-w-[300px] bg-white p-4 rounded shadow flex flex-col h-full overflow-y-auto">
            <h2 class="text-xl font-bold mb-4">Настройки</h2>
            
            <label class="block mb-1 text-sm font-bold">Длина дороги (L):</label>
            <input type="number" id="inp-roadLength" value="50" min="4" max="200" class="border p-2 w-full mb-3 rounded">

            <label class="block mb-1 text-sm font-bold">Кол-во машин (N):</label>
            <input type="number" id="inp-numberCars" value="5" class="border p-2 w-full mb-3 rounded">
            
            <label class="block mb-1 text-sm font-bold">Макс. скорость (vMax):</label>
            <input type="number" id="inp-vMax" value="3" class="border p-2 w-full mb-3 rounded">

            <label class="block mb-1 text-sm font-bold">Итерации (время):</label>
            <input type="number" id="inp-iterations" value="50" class="border p-2 w-full mb-5 rounded">

            <button id="btn-load" class="bg-blue-600 text-white p-3 rounded w-full hover:bg-blue-700 font-bold mb-6 transition">
                Загрузить модель
            </button>

            <!-- Разделитель -->
            <hr class="border-gray-200 mb-6">

            <h2 class="text-xl font-bold mb-4">Управление</h2>

            <!-- Кнопки управления -->
            <div class="flex flex-col gap-2 mb-4">
                <button id="btn-play" class="bg-green-500 hover:bg-green-600 text-white py-2 rounded font-bold disabled:opacity-50 transition" disabled>
                    ▶ Автовоспроизведение
                </button>
                
                <div class="flex gap-2">
                    <button id="btn-prev" class="w-1/2 bg-gray-200 hover:bg-gray-300 py-2 rounded font-bold disabled:opacity-50 transition" disabled>
                        < Назад
                    </button>
                    <button id="btn-next" class="w-1/2 bg-gray-200 hover:bg-gray-300 py-2 rounded font-bold disabled:opacity-50 transition" disabled>
                        Вперед >
                    </button>
                </div>
            </div>

            <!-- Счетчик шагов -->
            <div class="text-lg font-bold text-center mb-4">
                Шаг: <span id="step-counter" class="text-blue-600">0</span>
            </div>

            <!-- Подсказка -->
            <div class="mt-auto text-xs text-gray-400 bg-gray-50 p-2 rounded border">
                💡 <b>Зум:</b> Колесико мыши<br>
                ✋ <b>Движение:</b> Тяни мышкой
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА: Только визуализация -->
        <div class="w-3/4 bg-white rounded shadow flex flex-col p-1">
            <h1 class="text-xl font-bold m-3">Визуализация</h1>
            <!-- Контейнер занимает всё доступное место -->
            <div id="container" class="flex-grow border-2 border-gray-100 rounded bg-gray-50 overflow-hidden cursor-move"></div>
        </div>

    </div>

</body>
</html>