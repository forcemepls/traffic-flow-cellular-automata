<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Выбор модели</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-900 h-screen flex items-center justify-center font-sans text-white">

<div class="max-w-6xl w-full p-8">
    <h1 class="text-4xl font-bold text-center mb-12">Модели транспортного потока</h1>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">

        <!-- Карточка 1 -->
        <a href="/simulation_nagel" class="group block bg-gray-800 rounded-xl p-8 hover:bg-blue-900 transition border border-gray-700 hover:border-blue-500 shadow-lg transform hover:-translate-y-1">
            <div class="text-blue-400 mb-4">
                <svg class="w-12 h-12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                </svg>
            </div>
            <h2 class="text-2xl font-bold mb-2 group-hover:text-blue-300">Базовая модель</h2>
            <p class="text-gray-400">Классическая модель Нагеля-Шрекенберга. Однополосное движение, стохастическое поведение, образование фантомных пробок.</p>
        </a>

        <!-- Карточка 2 -->
        <a href="/extended_simulation_nagel" class="group block bg-gray-800 rounded-xl p-8 hover:bg-purple-900 transition border border-gray-700 hover:border-purple-500 shadow-lg transform hover:-translate-y-1">
            <div class="text-purple-400 mb-4">
                <svg class="w-12 h-12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24">
                    <line x1="3" y1="9" x2="21" y2="9"></line>
                    <line x1="3" y1="15" x2="21" y2="15"></line>
                </svg>
            </div>
            <h2 class="text-2xl font-bold mb-2 group-hover:text-purple-300">Расширенная модель</h2>
            <p class="text-gray-400">Двухполосное движение. Правила обгона и возврата в полосу. Более сложное взаимодействие потоков.</p>
        </a>

        <!-- Карточка 3 -->
        <a href="/t_junction" class="group block bg-gray-800 rounded-xl p-8 hover:bg-emerald-900 transition border border-gray-700 hover:border-emerald-500 shadow-lg transform hover:-translate-y-1">
            <div class="text-emerald-400 mb-4">
                <svg class="w-12 h-12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24">
                    <line x1="3" y1="7" x2="21" y2="7"></line>
                    <line x1="12" y1="7" x2="12" y2="21"></line>
                </svg>
            </div>
            <h2 class="text-2xl font-bold mb-2 group-hover:text-emerald-300">T-образный перекрёсток</h2>
            <p class="text-gray-400">Регулируемый светофором узел с двухполосными плечами, очередями, пуассоновским спавном и поворотами на S.</p>
        </a>

    </div>
</div>

</body>
</html>
