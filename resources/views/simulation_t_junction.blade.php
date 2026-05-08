<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>T-образный перекрёсток</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/t_junction.js'])
</head>
<body class="bg-gray-100 font-sans h-screen flex flex-col overflow-hidden relative">

<div class="flex gap-4 h-full p-4">

    <!-- ЛЕВАЯ КОЛОНКА -->
    <div class="w-1/4 min-w-[300px] bg-white p-5 rounded shadow flex flex-col h-full overflow-y-auto">

        <!-- Заголовок со стрелкой назад -->
        <div class="flex items-center gap-3 mb-6">
            <a href="/" class="p-2 -ml-2 rounded-lg hover:bg-gray-100 transition-all duration-200 group" title="Вернуться на главную">
                <svg class="w-8 h-8 text-gray-700 group-hover:text-blue-600 group-hover:scale-110 transition-all duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
            </a>
            <div class="leading-tight">
                <div class="text-xs text-gray-400 uppercase font-bold tracking-wider">Текущая модель</div>
                <div class="font-bold text-gray-800 text-lg leading-none">T-образный перекрёсток</div>
            </div>
        </div>

        <input type="hidden" id="inp-mode" value="tjunction">

        <hr class="border-gray-200 mb-6">

        <h3 class="font-bold text-gray-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
            </svg>
            Параметры дороги
        </h3>

        <label class="block mb-1 text-sm font-bold text-gray-600">Длина плеча (L):</label>
        <input type="number" id="inp-roadLength" value="15" min="20" max="200"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Итерации (шаги / секунды):</label>
        <input type="number" id="inp-iterations" value="300" min="10" max="3600"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Макс. скорость (vMax):</label>
        <input type="number" id="inp-vMax" value="4" min="1" max="10"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Вероятность торможения (p):</label>
        <input type="number" id="inp-p" value="0.1" step="0.05" min="0" max="1"
               class="border border-gray-300 p-2.5 w-full mb-5 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <h3 class="font-bold text-gray-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Светофор
        </h3>

        <label class="block mb-1 text-sm font-bold text-gray-600">Фаза главной (сек):</label>
        <input type="number" id="inp-tPhaseMain" value="15" min="5" max="300"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Фаза второстепенной (сек):</label>
        <input type="number" id="inp-tPhaseSec" value="10" min="5" max="300"
               class="border border-gray-300 p-2.5 w-full mb-5 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <h3 class="font-bold text-gray-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
            </svg>
            Интенсивность въездов (авт/мин)
        </h3>

        <label class="block mb-1 text-sm font-bold text-gray-600">Запад (λ<sub>W</sub>):</label>
        <input type="number" id="inp-lambdaW" value="30" min="0" max="120" step="1"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Восток (λ<sub>E</sub>):</label>
        <input type="number" id="inp-lambdaE" value="30" min="0" max="120" step="1"
               class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Юг (λ<sub>S</sub>):</label>
        <input type="number" id="inp-lambdaS" value="15" min="0" max="120" step="1"
               class="border border-gray-300 p-2.5 w-full mb-5 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <!-- КНОПКА ЗАГРУЗИТЬ МОДЕЛЬ -->
        <button id="btn-load"
                class="w-full mb-6 flex items-center justify-center gap-2
                       bg-gradient-to-r from-blue-500 to-cyan-500
                       hover:from-blue-600 hover:to-cyan-600
                       text-white font-semibold
                       px-4 py-3.5
                       rounded-xl
                       shadow-lg hover:shadow-xl hover:shadow-blue-500/25
                       transform hover:scale-[1.02] active:scale-[0.98]
                       transition-all duration-200
                       focus:outline-none focus:ring-2 focus:ring-blue-400 focus:ring-offset-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
            </svg>
            <span>Загрузить модель</span>
        </button>

        <h3 class="font-bold text-gray-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            Управление плеером
        </h3>

        <div class="flex flex-col gap-3 mb-4">
            <button id="btn-play"
                    class="w-full flex items-center justify-center gap-2
                           bg-gradient-to-r from-emerald-500 to-green-500
                           hover:from-emerald-600 hover:to-green-600
                           text-white font-semibold
                           px-4 py-3
                           rounded-xl
                           shadow-lg hover:shadow-xl hover:shadow-green-500/25
                           transform hover:scale-[1.02] active:scale-[0.98]
                           transition-all duration-200
                           focus:outline-none focus:ring-2 focus:ring-green-400 focus:ring-offset-2
                           disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none disabled:hover:scale-100 disabled:shadow-none"
                    disabled>
                <svg id="icon-play" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M8 5v14l11-7z"></path>
                </svg>
                <span id="text-play">Воспроизведение</span>
            </button>

            <div class="flex gap-3">
                <button id="btn-prev"
                        class="w-1/2 flex items-center justify-center gap-1.5
                               bg-gradient-to-r from-slate-500 to-gray-500
                               hover:from-slate-600 hover:to-gray-600
                               text-white font-semibold
                               px-3 py-2.5
                               rounded-xl
                               shadow-md hover:shadow-lg hover:shadow-gray-500/20
                               transform hover:scale-[1.02] active:scale-[0.98]
                               transition-all duration-200
                               focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2
                               disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none disabled:hover:scale-100 disabled:shadow-none"
                        disabled>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    <span>Назад</span>
                </button>

                <button id="btn-next"
                        class="w-1/2 flex items-center justify-center gap-1.5
                               bg-gradient-to-r from-slate-500 to-gray-500
                               hover:from-slate-600 hover:to-gray-600
                               text-white font-semibold
                               px-3 py-2.5
                               rounded-xl
                               shadow-md hover:shadow-lg hover:shadow-gray-500/20
                               transform hover:scale-[1.02] active:scale-[0.98]
                               transition-all duration-200
                               focus:outline-none focus:ring-2 focus:ring-gray-400 focus:ring-offset-2
                               disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none disabled:hover:scale-100 disabled:shadow-none"
                        disabled>
                    <span>Вперёд</span>
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Счётчик шага -->
        <div class="text-center mb-3 bg-gradient-to-r from-gray-50 to-slate-100 p-4 rounded-xl border border-gray-200">
            <div class="text-xs text-gray-500 uppercase tracking-wider font-semibold mb-1">Текущий шаг</div>
            <div class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-cyan-600 font-mono" id="step-counter">0</div>
        </div>

        <!-- Индикатор фазы -->
        <div class="mb-3 p-3 rounded-xl border text-center" id="phase-indicator">
            <div class="text-xs uppercase font-bold tracking-wider text-gray-500">Текущая фаза</div>
            <div class="text-lg font-bold mt-1" id="phase-label">—</div>
        </div>

        <!-- Очереди -->
        <div class="mb-3 p-3 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-xs uppercase font-bold tracking-wider text-gray-500 mb-2">Очереди на въездах</div>
            <div class="flex justify-between text-sm">
                <span>Запад: <b id="q-W">0</b></span>
                <span>Восток: <b id="q-E">0</b></span>
                <span>Юг: <b id="q-S">0</b></span>
            </div>
        </div>

        <!-- Счётчики машин -->
        <div class="mb-3 p-3 rounded-xl border border-gray-200 bg-gray-50">
            <div class="text-xs uppercase font-bold tracking-wider text-gray-500 mb-2">Машины</div>
            <div class="flex justify-between text-sm">
                <span>Создано: <b id="cnt-spawned">0</b></span>
                <span>Выехало: <b id="cnt-finished">0</b></span>
            </div>
        </div>

        <div class="mt-auto text-sm text-gray-500 p-4 rounded-xl bg-gradient-to-br from-amber-50 to-yellow-50 border border-amber-200">
            <div class="flex items-start gap-2">
                <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"></path>
                </svg>
                <div>
                    <div class="font-semibold text-amber-800 mb-1">Управление сценой</div>
                    <div class="text-amber-700">
                        <b>Зум:</b> колёсико мыши<br>
                        <b>Панорама:</b> перетаскивание
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ПРАВАЯ КОЛОНКА -->
    <div class="w-3/4 bg-white rounded shadow flex flex-col p-1 relative overflow-hidden">
        <div id="container" class="w-full h-full cursor-move"></div>
    </div>

</div>

</body>
</html>
