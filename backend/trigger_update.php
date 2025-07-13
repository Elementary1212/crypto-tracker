<?php
header('Content-Type: application/json');

// Получаем секрет из переменных окружения
$secret = getenv('UPDATE_SECRET') ?: 'default_secret';

// Проверяем секретный ключ
if (!isset($_GET['secret']) || $_GET['secret'] !== $secret) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Запускаем скрипт обновления в фоновом режиме
$output = [];
$returnCode = 0;
$command = 'php ' . __DIR__ . '/fetch_data.php > /dev/null 2>&1 &';
exec($command, $output, $returnCode);

if ($returnCode === 0) {
    echo json_encode(['success' => true, 'message' => 'Обновление запущено']);
} else {
    echo json_encode(['error' => 'Не удалось запустить обновление', 'code' => $returnCode]);
}