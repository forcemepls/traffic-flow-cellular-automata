<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>T-образный перекрёсток</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/t_junction.js'])
</head>
<body class="bg-gray-100 font-sans">

<div class="flex gap-4 h-screen p-4">

    <!-- ЛЕВАЯ КОЛОНКА — параметры -->
    <div class="w-1/4 min-w-[280px] bg-white p-5 rounded shadow overflow-y-auto">

        <div class="flex items-center gap-3 mb-6">
            <a href="/" class="p-2 rounded-lg hover:bg-gray-100 transition-all group">
                <svg class="w-8 h-8 text-gray-700 group-hover:text-blue-600 transition-all" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
            </a>
            <div>
                <div class="text-xs text-gray-400 uppercase font-bold tracking-wider">Текущая модель</div>
                <div class="font-bold text-gray-800 text-lg">T-образный перекрёсток</div>
            </div>
        </div>

        <input type="hidden" id="inp-mode" value="tjunction">
        <hr class="border-gray-200 mb-4">

        <h3 class="font-bold text-gray-700 mb-3">Параметры дороги</h3>

        <label class="block mb-1 text-sm font-bold text-gray-600">Длина плеча (L):</label>
        <input type="number" id="inp-roadLength" value="50" min="20" max="200"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <label class="block mb-1 text-sm font-bold text-gray-600">Итерации (шаги / секунды):</label>
        <input type="number" id="inp-iterations" value="300" min="10" max="3600"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <label class="block mb-1 text-sm font-bold text-gray-600">Макс. скорость (vMax):</label>
        <input type="number" id="inp-vMax" value="4" min="1" max="10"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <label class="block mb-1 text-sm font-bold text-gray-600">Вероятность торможения (p):</label>
        <input type="number" id="inp-p" value="0.3" step="0.05" min="0" max="1"
               class="border border-gray-300 p-2.5 w-full mb-5 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <hr class="border-gray-200 mb-4">
        <h3 class="font-bold text-gray-700 mb-3">Светофор</h3>

        <label class="block mb-1 text-sm font-bold text-gray-600">Фаза главной (сек):</label>
        <input type="number" id="inp-tPhaseMain" value="60" min="5" max="300"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <label class="block mb-1 text-sm font-bold text-gray-600">Фаза второстепенной (сек):</label>
        <input type="number" id="inp-tPhaseSec" value="30" min="5" max="300"
               class="border border-gray-300 p-2.5 w-full mb-5 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <hr class="border-gray-200 mb-4">
        <h3 class="font-bold text-gray-700 mb-3">Интенсивность въездов (авт/мин)</h3>

        <label class="block mb-1 text-sm font-bold text-gray-600">Запад (λ<sub>W</sub>):</label>
        <input type="number" id="inp-lambdaW" value="30" min="0" max="120" step="1"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <label class="block mb-1 text-sm font-bold text-gray-600">Восток (λ<sub>E</sub>):</label>
        <input type="number" id="inp-lambdaE" value="30" min="0" max="120" step="1"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <label class="block mb-1 text-sm font-bold text-gray-600">Юг (λ<sub>S</sub>):</label>
        <input type="number" id="inp-lambdaS" value="15" min="0" max="120" step="1"
               class="border border-gray-300 p-2.5 w-full mb-5 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 outline-none transition-all">

        <button id="btn-load"
                class="w-full flex items-center justify-center gap-2
                       bg-gradient-to-r from-blue-500 to-cyan-500
                       hover:from-blue-600 hover:to-cyan-600
                       text-white font-semibold px-4 py-3.5 rounded-xl
                       shadow-lg hover:shadow-xl hover:shadow-blue-500/25
                       transform hover:scale-[1.02] active:scale-[0.98]
                       transition-all duration-200 focus:outline-none">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            <span>Загрузить модель</span>
        </button>

        <!-- Счётчик шага -->
        <div class="text-center mt-4 bg-gradient-to-r from-gray-50 to-slate-100 p-4 rounded-xl border border-gray-200">
            <div class="text-xs text-gray-500 uppercase tracking-wider font-semibold mb-1">Текущий шаг</div>
            <div class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-cyan-600 font-mono" id="step-counter">0</div>
        </div>

        <!-- Индикатор фазы -->
        <div class="mt-3 p-3 rounded-xl border text-center" id="phase-indicator">
            <div class="text-xs uppercase font-bold tracking-wider text-gray-500">Текущая фаза</div>
            <div class="text-lg font-bold mt-1" id="phase-label">—</div>
        </div>

        <!-- Очереди -->
        <div class="mt-3 p-3 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-xs uppercase font-bold tracking-wider text-gray-500 mb-2">Очереди на въездах</div>
            <div class="flex justify-between text-sm">
                <span>Запад: <b id="q-W">0</b></span>
                <span>Восток: <b id="q-E">0</b></span>
                <span>Юг: <b id="q-S">0</b></span>
            </div>
        </div>

        <!-- Счётчики машин -->
        <div class="mt-3 p-3 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-xs uppercase font-bold tracking-wider text-gray-500 mb-2">Машины</div>
            <div class="flex justify-between text-sm">
                <span>Создано: <b id="cnt-spawned">0</b></span>
                <span>Выехало: <b id="cnt-finished">0</b></span>
            </div>
        </div>

    </div>

    <!-- ПРАВАЯ КОЛОНКА — рендер -->
    <div class="flex-1 bg-white rounded shadow relative overflow-hidden">
        <div id="container" class="w-full h-full cursor-move"></div>

        <!-- Кнопки управления плеером -->
        <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-3">
            <button id="btn-prev"
                    class="bg-slate-600 hover:bg-slate-700 text-white font-semibold px-4 py-2 rounded-xl shadow disabled:opacity-40"
                    disabled>← Назад</button>
            <button id="btn-play"
                    class="bg-emerald-500 hover:bg-emerald-600 text-white font-semibold px-6 py-2 rounded-xl shadow disabled:opacity-40"
                    disabled>▶ Старт</button>
            <button id="btn-next"
                    class="bg-slate-600 hover:bg-slate-700 text-white font-semibold px-4 py-2 rounded-xl shadow disabled:opacity-40"
                    disabled>Вперёд →</button>
        </div>
    </div>
</div>

</body>
</html>
