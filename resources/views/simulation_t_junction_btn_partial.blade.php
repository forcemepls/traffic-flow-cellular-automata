<!--
  МОДАЛКА СТАТИСТИКИ T-ПЕРЕКРЁСТКА.

  Куда вставлять:
  1) Блок ниже — целиком ВСТАВИТЬ в simulation_t_junction.blade.php
     перед закрывающим </body>.

  2) Где-то в UI (рядом с кнопкой «Загрузить модель» или в плеере)
     добавить кнопку открытия:

         <button id="btn-statistics"
             class="bg-gradient-to-r from-indigo-500 to-purple-600 ...">
             Показать статистику
         </button>

     ID кнопки должен быть btn-statistics — t_junction.js его слушает.
-->

<!-- === МОДАЛЬНОЕ ОКНО СТАТИСТИКИ === -->
<div id="statistics-modal" class="fixed inset-0 z-50 hidden">
    <div id="modal-backdrop" class="absolute inset-0 bg-black/50 backdrop-blur-sm"></div>

    <div class="absolute inset-4 md:inset-8 lg:inset-12 flex items-center justify-center">
        <div class="bg-white rounded-2xl shadow-2xl w-full h-full max-w-6xl max-h-[90vh] flex flex-col overflow-hidden">

            <!-- Заголовок -->
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
                                    <p class="text-sm text-gray-500">Усреднено по всем машинам в системе на каждом шаге</p>
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

                    <!-- Карточка 3: Очереди по плечам -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-yellow-500 to-amber-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Длины очередей по плечам</h3>
                                    <p class="text-sm text-gray-500">Машины на въездных полосах перед узлом</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-4 mb-4">
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">W (запад)</div>
                                    <div id="stat-queue-w" class="text-2xl font-bold text-yellow-600">—</div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">E (восток)</div>
                                    <div id="stat-queue-e" class="text-2xl font-bold text-amber-600">—</div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">S (юг)</div>
                                    <div id="stat-queue-s" class="text-2xl font-bold text-orange-600">—</div>
                                </div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 300px;">
                                <canvas id="chart-queues"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 4: Пропускная способность по фазам -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-green-500 to-emerald-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Пропускная способность по фазам</h3>
                                    <p class="text-sm text-gray-500">Нарастающее число пересечений узла в каждой фазе</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4 mb-4">
                                <div class="bg-gray-50 rounded-xl p-4">
                                    <div class="text-gray-600 text-sm mb-1">Фаза MAIN (W↔E прямо)</div>
                                    <div id="stat-thr-main" class="text-2xl font-bold text-green-600">—</div>
                                    <div class="text-xs text-gray-400 mt-1"><span id="stat-thr-main-h">—</span> авто/час</div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-4">
                                    <div class="text-gray-600 text-sm mb-1">Фаза SEC (повороты + S)</div>
                                    <div id="stat-thr-sec" class="text-2xl font-bold text-emerald-600">—</div>
                                    <div class="text-xs text-gray-400 mt-1"><span id="stat-thr-sec-h">—</span> авто/час</div>
                                </div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 280px;">
                                <canvas id="chart-throughput"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 5: Время ожидания -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-purple-500 to-pink-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Время ожидания на въездах</h3>
                                    <p class="text-sm text-gray-500">Среднее число шагов с v=0 на DIR_IN до пересечения узла</p>
                                </div>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-gray-600">В среднем по всему перекрёстку:</span>
                                    <span id="stat-wait-total" class="text-2xl font-bold text-purple-600">—</span>
                                </div>
                                <div class="text-xs text-gray-400">шагов = секунд</div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 280px;">
                                <canvas id="chart-wait"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 6: Баланс системы -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-teal-500 to-cyan-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8m-7 0h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Баланс системы</h3>
                                    <p class="text-sm text-gray-500">Создано / выехало / находится в системе по времени</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-4 mb-4">
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">Всего создано</div>
                                    <div id="stat-total-created" class="text-2xl font-bold text-teal-600">—</div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">Всего выехало</div>
                                    <div id="stat-total-exited" class="text-2xl font-bold text-cyan-600">—</div>
                                </div>
                                <div class="bg-gray-50 rounded-xl p-4 text-center">
                                    <div class="text-gray-600 text-sm mb-1">В системе сейчас</div>
                                    <div id="stat-final-system" class="text-2xl font-bold text-blue-600">—</div>
                                </div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 280px;">
                                <canvas id="chart-balance"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Карточка 7: Распределение манёвров -->
                    <div class="card-slide flex-shrink-0 w-full h-full p-6 overflow-y-auto">
                        <div class="max-w-4xl mx-auto">
                            <div class="flex items-center gap-3 mb-4">
                                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-fuchsia-500 to-purple-500 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-gray-800">Распределение манёвров</h3>
                                    <p class="text-sm text-gray-500">Сколько машин фактически выполнило каждый манёвр</p>
                                </div>
                            </div>
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 360px;">
                                <canvas id="chart-manoeuvres"></canvas>
                            </div>
                            <div class="bg-gradient-to-br from-fuchsia-50 to-purple-50 border border-fuchsia-200 rounded-xl p-4 mt-4">
                                <p class="text-sm text-fuchsia-700">
                                    <b>Замечание:</b> учитываются <b>фактические</b> манёвры — те, что машина выполнила по факту полосы.
                                    Если машина не успела перестроиться, её маршрут корректируется автоматически (правая полоса → прямой проезд, левая → поворот на S).
                                </p>
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
                            <div class="bg-white border border-gray-200 rounded-xl p-4" style="height: 240px;">
                                <canvas id="chart-fundamental"></canvas>
                            </div>
                            <div class="bg-gradient-to-br from-rose-50 to-pink-50 border border-rose-200 rounded-xl p-4 mt-4">
                                <p class="text-sm text-rose-700">
                                    <b>Формула:</b> ρ = (среднее число машин в системе) / (суммарная длина дорог); J = (всего выехавших) / (число шагов).
                                </p>
                            </div>
                        </div>
                    </div>

                </div>

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
