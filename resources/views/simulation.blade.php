<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Транспортное моделирование с использованием клеточных автоматов</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <!-- Подключим иконки (Heroicons) через CDN для красоты -->
    <script src="https://unpkg.com/@heroicons/v1/outline.js"></script>
</head>
<body class="bg-gray-100 font-sans h-screen flex flex-col overflow-hidden relative">

    <!-- === БУРГЕР МЕНЮ (ПАНЕЛЬ) === -->
    <!-- Затемнение фона -->
    <div id="menu-backdrop" class="fixed inset-0 bg-gray-900/20 backdrop-blur-sm z-40 hidden transition-all duration-300"></div>
    
    <!-- Сама панель -->
    <div id="menu-drawer" class="fixed top-0 left-0 w-80 h-full bg-white shadow-2xl z-50 transform -translate-x-full transition-transform duration-300 ease-in-out p-6">
        <div class="flex justify-between items-center mb-8">
            <h2 class="text-2xl font-bold text-gray-800">Выбор модели</h2>
            <button id="btn-close-menu" class="text-gray-500 hover:text-gray-800">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>

        <nav class="space-y-4">
            <!-- Список моделей -->
            <button class="model-select-btn w-full text-left p-4 rounded-lg border hover:bg-blue-50 hover:border-blue-500 transition group" data-value="nagelschreckenberg">
                <h3 class="font-bold text-gray-800 group-hover:text-blue-700">Базовая модель</h3>
                <p class="text-sm text-gray-500 mt-1">Классическая модель Нагеля-Шрекенберга (одна полоса).</p>
            </button>

            <button class="model-select-btn w-full text-left p-4 rounded-lg border hover:bg-blue-50 hover:border-blue-500 transition group" data-value="extendednagelschreckenberg">
                <h3 class="font-bold text-gray-800 group-hover:text-blue-700">Расширенная модель</h3>
                <p class="text-sm text-gray-500 mt-1">Двухполосная модель с правилами обгона и возврата в ряд.</p>
            </button>

            <!-- Задел на будущее -->
            <button class="w-full text-left p-4 rounded-lg border border-dashed text-gray-400 cursor-not-allowed">
                <h3 class="font-bold">VDR Модель</h3>
                <p class="text-sm mt-1">В разработке...</p>
            </button>
        </nav>
    </div>


    <!-- === ОСНОВНОЙ ИНТЕРФЕЙС === -->
    <div class="flex gap-4 h-full p-4">
        
        <!-- ЛЕВАЯ КОЛОНКА -->
        <div class="w-1/4 min-w-[300px] bg-white p-5 rounded shadow flex flex-col h-full overflow-y-auto">
            
            <!-- Заголовок с бургером -->
            <div class="flex items-center gap-3 mb-6">
                <button id="btn-open-menu" class="p-2 -ml-2 rounded-md hover:bg-gray-100 transition">
                    <svg class="w-8 h-8 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <div class="leading-tight">
                    <div class="text-xs text-gray-400 uppercase font-bold tracking-wider">Текущая модель</div>
                    <div id="current-model-name" class="font-bold text-gray-800 text-lg leading-none">Базовая модель</div>
                </div>
            </div>

            <!-- СКРЫТЫЙ ИНПУТ (сюда пишем значение из меню) -->
            <input type="hidden" id="inp-mode" value="nagelschreckenberg">

            <hr class="border-gray-200 mb-6">
            
            <h3 class="font-bold text-gray-700 mb-3">Параметры</h3>
            
            <label class="block mb-1 text-sm font-bold text-gray-600">Длина дороги (L):</label>
            <input type="number" id="inp-roadLength" value="50" min="4" max="200" class="border p-2 w-full mb-3 rounded bg-gray-50 focus:bg-white transition">

            <label class="block mb-1 text-sm font-bold text-gray-600">Кол-во машин (N):</label>
            <input type="number" id="inp-numberCars" value="5" class="border p-2 w-full mb-3 rounded bg-gray-50 focus:bg-white transition">
            
            <label class="block mb-1 text-sm font-bold text-gray-600">Макс. скорость (vMax):</label>
            <input type="number" id="inp-vMax" value="3" class="border p-2 w-full mb-3 rounded bg-gray-50 focus:bg-white transition">

            <label class="block mb-1 text-sm font-bold text-gray-600">Итерации (время):</label>
            <input type="number" id="inp-iterations" value="50" class="border p-2 w-full mb-5 rounded bg-gray-50 focus:bg-white transition">

            <button id="btn-load" class="bg-blue-600 text-white p-3 rounded w-full hover:bg-blue-700 font-bold mb-6 transition shadow-lg transform active:scale-95">
                Загрузить модель
            </button>

            <h3 class="font-bold text-gray-700 mb-3">Управление плеером</h3>

            <div class="flex flex-col gap-2 mb-4">
                <button id="btn-play" class="bg-green-500 hover:bg-green-600 text-white py-3 rounded font-bold disabled:opacity-50 disabled:cursor-not-allowed transition shadow" disabled>
                    ▶ Автовоспроизведение
                </button>
                
                <div class="flex gap-2">
                    <button id="btn-prev" class="w-1/2 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded font-bold disabled:opacity-50 disabled:cursor-not-allowed transition" disabled>
                        < Назад
                    </button>
                    <button id="btn-next" class="w-1/2 bg-gray-200 hover:bg-gray-300 text-gray-700 py-2 rounded font-bold disabled:opacity-50 disabled:cursor-not-allowed transition" disabled>
                        Вперед >
                    </button>
                </div>
            </div>

            <div class="text-lg font-bold text-center mb-4 bg-gray-50 p-2 rounded">
                Шаг: <span id="step-counter" class="text-blue-600 font-mono">0</span>
            </div>

            <div class="mt-auto text-xs text-gray-400 p-2 rounded border border-dashed border-gray-300">
                💡 <b>Зум:</b> Колесико<br>
                ✋ <b>Панорама:</b> Drag & Drop
            </div>
        </div>

        <!-- ПРАВАЯ КОЛОНКА -->
        <div class="w-3/4 bg-white rounded shadow flex flex-col p-1 relative overflow-hidden">
            <div id="container" class="w-full h-full cursor-move"></div>
        </div>

    </div>
</body>
</html>