<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Выбор модели</title>
    @vite(['resources/css/app.css'])
</head>
<body class="bg-gray-900 h-screen flex items-center justify-center font-sans text-white">

    <div class="max-w-4xl w-full p-8">
        <h1 class="text-4xl font-bold text-center mb-12">Транспортное моделирование</h1>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
            
            <!-- Карточка 1 -->
            <a href="/simulation_nagel" class="group block bg-gray-800 rounded-xl p-8 hover:bg-blue-900 transition border border-gray-700 hover:border-blue-500 shadow-lg transform hover:-translate-y-1">
                <div class="text-blue-400 mb-4">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <h2 class="text-2xl font-bold mb-2 group-hover:text-blue-300">Базовая модель</h2>
                <p class="text-gray-400">Классическая модель Нагеля-Шрекенберга. Однополосное движение, стохастическое поведение, образование фантомных пробок.</p>
            </a>

            <!-- Карточка 2 -->
            <a href="/extended_simulation_nagel" class="group block bg-gray-800 rounded-xl p-8 hover:bg-purple-900 transition border border-gray-700 hover:border-purple-500 shadow-lg transform hover:-translate-y-1">
                <div class="text-purple-400 mb-4">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                </div>
                <h2 class="text-2xl font-bold mb-2 group-hover:text-purple-300">Расширенная модель</h2>
                <p class="text-gray-400">Двухполосное движение. Правила обгона и возврата в полосу. Более сложное взаимодействие потоков.</p>
            </a>

        </div>
    </div>

</body>
</html>