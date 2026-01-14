<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Симуляция</title>
    <!-- Подключение стилей и скриптов через Vite -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 p-5">
    <h1>Модель Нагеля-Шрекенберга</h1>
    
    <!-- Контейнер для графики -->
    <div id="container" style="background: white; border: 1px solid black; height: 200px; width: 800px;"></div>

    <button id="btn-start">Старт</button>
</body>
</html>