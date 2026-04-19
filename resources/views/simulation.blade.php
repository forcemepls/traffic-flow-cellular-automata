<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Транспортное моделирование' }}</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 font-sans h-screen flex flex-col overflow-hidden relative">

<!-- === ОСНОВНОЙ ИНТЕРФЕЙС === -->
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
                <div id="current-model-name" class="font-bold text-gray-800 text-lg leading-none">{{ $title ?? 'Базовая модель' }}</div>
            </div>
        </div>

        <input type="hidden" id="inp-mode" value="{{ ($mode ?? 'basic') === 'extended' ? 'extendednagelschreckenberg' : 'nagelschreckenberg' }}">

        <hr class="border-gray-200 mb-6">

        <h3 class="font-bold text-gray-700 mb-3 flex items-center gap-2">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
            </svg>
            Параметры
        </h3>

        <label class="block mb-1 text-sm font-bold text-gray-600">Длина дороги (L):</label>
        <input type="number" id="inp-roadLength" value="50" min="4" max="200" class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Кол-во машин (N):</label>
        <input type="number" id="inp-numberCars" value="5" class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Макс. скорость (vMax):</label>
        <input type="number" id="inp-vMax" value="3" class="border border-gray-300 p-2.5 w-full mb-3 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

        <label class="block mb-1 text-sm font-bold text-gray-600">Итерации (время):</label>
        <input type="number" id="inp-iterations" value="50" class="border border-gray-300 p-2.5 w-full mb-5 rounded-lg bg-gray-50 focus:bg-white focus:border-blue-500 focus:ring-2 focus:ring-blue-200 transition-all duration-200 outline-none">

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

        <div class="text-center mb-4 bg-gradient-to-r from-gray-50 to-slate-100 p-4 rounded-xl border border-gray-200">
            <div class="text-xs text-gray-500 uppercase tracking-wider font-semibold mb-1">Текущий шаг</div>
            <div class="text-3xl font-bold text-transparent bg-clip-text bg-gradient-to-r from-blue-600 to-cyan-600 font-mono" id="step-counter">0</div>
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

        <!-- Кнопка Статистика -->
        <button
            id="btn-statistics"
            class="absolute top-3 right-3 z-10
                       flex items-center gap-2
                       bg-gradient-to-r from-indigo-500 to-purple-600
                       hover:from-indigo-600 hover:to-purple-700
                       text-white font-semibold
                       px-4 py-2.5
                       rounded-xl
                       shadow-lg hover:shadow-xl hover:shadow-purple-500/25
                       transform hover:scale-105 active:scale-95
                       transition-all duration-200
                       focus:outline-none focus:ring-2 focus:ring-purple-400 focus:ring-offset-2
                       disabled:opacity-50 disabled:cursor-not-allowed"
            title="Открыть статистику симуляции"
            disabled>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
            </svg>
            <span>Статистика</span>
        </button>

        <div id="container" class="w-full h-full cursor-move"></div>
    </div>

</div>

<!-- === МОДАЛЬНОЕ ОКНО СТАТИСТИКИ === -->
<div id="statistics-modal" class="fixed inset-0 z-50 hidden">
    <!-- Затемнение фона -->
    <div id="modal-backdrop" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

    <!-- Контейнер модального окна -->
    <div class="absolute inset-4 md:inset-8 lg:inset-12 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full h-full max-w-6xl max-h-[90vh] flex flex-col overflow-hidden">

            <!-- Заголовок модального окна -->
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-indigo-500 to-purple-600">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    Статистика симуляции
                </h2>
                <button id="modal-close" class="p-2 hover:bg-white/20 rounded-lg transition-colors">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <!-- Контент карточек -->
            <div class="flex-1 overflow-hidden relative">
                <!-- Карусель карточек -->
                <div id="cards-container" class="flex transition-transform duration-300 h-full">

                    <!-- Карточка 1: Средняя скорость -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-blue-500 to-cyan-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Средняя скорость потока</h3>
                                    <p class="text-sm text-gray-500">Динамика изменения скорости по шагам симуляции</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-gray-600">Средняя скорость за всю симуляцию:</span>
                                    <span id="stat-avg-speed" class="text-2xl font-bold text-blue-600">—</span>
                                </div>
                                <div class="text-xs text-gray-400">клеток/шаг</div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 300px;">
                                <canvas id="chart-speed"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 2: Коэффициент заторов -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-red-500 to-orange-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Коэффициент заторов</h3>
                                    <p class="text-sm text-gray-500">Доля автомобилей с нулевой скоростью</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-gray-600">Средний коэффициент заторов:</span>
                                    <span id="stat-congestion" class="text-2xl font-bold text-red-600">—</span>
                                </div>
                                <div class="text-xs text-gray-400">0 = свободный поток, 1 = полная пробка</div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 300px;">
                                <canvas id="chart-congestion"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 3: Индекс замедлений -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-yellow-500 to-amber-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Индекс замедлений</h3>
                                    <p class="text-sm text-gray-500">Доля автомобилей, снизивших скорость на каждом шаге</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-gray-600">Средний индекс замедлений:</span>
                                    <span id="stat-braking" class="text-2xl font-bold text-yellow-600">—</span>
                                </div>
                                <div class="text-xs text-gray-400">Высокий индекс = нестабильный поток</div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 300px;">
                                <canvas id="chart-braking"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 4: Средний Gap -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-green-500 to-emerald-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Средняя дистанция (Gap)</h3>
                                    <p class="text-sm text-gray-500">Среднее расстояние до впереди идущего автомобиля</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-gray-600">Средний Gap за симуляцию:</span>
                                    <span id="stat-gap" class="text-2xl font-bold text-green-600">—</span>
                                </div>
                                <div class="text-xs text-gray-400">клеток</div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 300px;">
                                <canvas id="chart-gap"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 5: Интенсивность перестроений -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Интенсивность перестроений</h3>
                                    <p class="text-sm text-gray-500">Активность смены полос движения</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-gray-600">Всего перестроений:</span>
                                    <span id="stat-lane-changes" class="text-2xl font-bold text-purple-600">—</span>
                                </div>
                                <div class="text-xs text-gray-400">за всю симуляцию</div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 300px;">
                                <canvas id="chart-lane-changes"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 6: Эффективность обгонов -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-teal-500 to-cyan-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Эффективность обгонов</h3>
                                    <p class="text-sm text-gray-500">Изменение скорости после манёвра обгона</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                <div class="flex items-center justify-center">
                                    <div class="text-center">
                                        <div id="stat-overtake" class="text-5xl font-bold text-teal-600 mb-2">—</div>
                                        <div class="text-gray-500">относительное изменение скорости</div>
                                        <div class="text-xs text-gray-400 mt-1">>0 — обгон эффективен, <0 — неэффективен</div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gradient-to-br from-teal-50 to-cyan-50 border border-teal-200 rounded-xl p-6">
                                <h4 class="font-semibold text-teal-800 mb-3">Интерпретация:</h4>
                                <ul class="space-y-2 text-sm text-teal-700">
                                    <li class="flex items-start gap-2">
                                        <span class="text-teal-500">•</span>
                                        <span><b>Положительное значение</b> — обгоны приводят к увеличению скорости</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <span class="text-teal-500">•</span>
                                        <span><b>Около нуля</b> — нейтральный эффект</span>
                                    </li>
                                    <li class="flex items-start gap-2">
                                        <span class="text-teal-500">•</span>
                                        <span><b>Отрицательное значение</b> — обгоны неэффективны (возможно из-за высокой плотности)</span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 7: Время в пути -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Время в пути</h3>
                                    <p class="text-sm text-gray-500">Время прохождения полного круга каждым автомобилем</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <span class="text-gray-600 text-sm">Идеальное время (при vMax):</span>
                                        <div id="stat-ideal-time" class="text-xl font-bold text-gray-800">—</div>
                                    </div>
                                    <div>
                                        <span class="text-gray-600 text-sm">Завершили круг:</span>
                                        <div id="stat-completed-laps" class="text-xl font-bold text-indigo-600">—</div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 300px;">
                                <canvas id="chart-travel-time"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 8: Фундаментальная диаграмма -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-rose-500 to-pink-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Фундаментальная диаграмма</h3>
                                    <p class="text-sm text-gray-500">Зависимость потока от плотности J(ρ)</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-4 mb-4">
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">Плотность (ρ)</div>
                                    <div id="stat-density" class="text-2xl font-bold text-rose-600">—</div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">Поток (J)</div>
                                    <div id="stat-flow" class="text-2xl font-bold text-pink-600">—</div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">Скорость (v̄)</div>
                                    <div id="stat-fd-speed" class="text-2xl font-bold text-purple-600">—</div>
                                </div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 280px;">
                                <canvas id="chart-fundamental"></canvas>
                            </div>
                            <div class="bg-gradient-to-br from-rose-50 to-pink-50 border border-rose-200 rounded-xl p-4 mt-4">
                                <p class="text-sm text-rose-700">
                                    <b>Формула:</b> J = ρ × v̄<br>
                                    Текущая точка показывает состояние данной симуляции на фундаментальной диаграмме.
                                </p>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- Стрелки навигации -->
                <button id="btn-prev-card" class="absolute left-4 top-1/2 -translate-y-1/2 w-12 h-12 bg-white/90 hover:bg-white rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-110 disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                </button>
                <button id="btn-next-card" class="absolute right-4 top-1/2 -translate-y-1/2 w-12 h-12 bg-white/90 hover:bg-white rounded-full shadow-lg flex items-center justify-center transition-all hover:scale-110 disabled:opacity-30 disabled:cursor-not-allowed">
                    <svg class="w-6 h-6 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </button>
            </div>

            <!-- Индикаторы (точки) -->
            <div class="flex justify-center gap-2 py-4 border-t border-gray-200">
                <button class="card-dot w-3 h-3 rounded-full bg-indigo-500 transition-all" data-index="0"></button>
                <button class="card-dot w-3 h-3 rounded-full bg-gray-300 hover:bg-gray-400 transition-all" data-index="1"></button>
                <button class="card-dot w-3 h-3 rounded-full bg-gray-300 hover:bg-gray-400 transition-all" data-index="2"></button>
                <button class="card-dot w-3 h-3 rounded-full bg-gray-300 hover:bg-gray-400 transition-all" data-index="3"></button>
                <button class="card-dot w-3 h-3 rounded-full bg-gray-300 hover:bg-gray-400 transition-all" data-index="4"></button>
                <button class="card-dot w-3 h-3 rounded-full bg-gray-300 hover:bg-gray-400 transition-all" data-index="5"></button>
                <button class="card-dot w-3 h-3 rounded-full bg-gray-300 hover:bg-gray-400 transition-all" data-index="6"></button>
                <button class="card-dot w-3 h-3 rounded-full bg-gray-300 hover:bg-gray-400 transition-all" data-index="7"></button>
            </div>
        </div>
    </div>
</div>

</body>
</html>
