<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=utf-8");

require 'db_connect.php';

// Простая функция для получения данных из файла
function getFallbackData() {
    if (file_exists('last_data.json')) {
        $fallbackData = json_decode(file_get_contents('last_data.json'), true);
        if (is_array($fallbackData)) {
            $result = [];
            foreach ($fallbackData as $coin) {
                $sparkline = $coin['sparkline_in_7d']['price'] ?? array_fill(0, 7, (float)($coin['current_price'] ?? 0));
                $result[] = [
                    'coin_id' => $coin['id'] ?? 'unknown',
                    'ticker' => strtoupper($coin['symbol'] ?? 'UNK'),
                    'name' => $coin['name'] ?? 'Неизвестная монета',
                    'price' => (float)($coin['current_price'] ?? 0),
                    'change_24h' => (float)($coin['price_change_percentage_24h'] ?? 0),
                    'market_cap' => (float)($coin['market_cap'] ?? 0),
                    'volume_24h' => (float)($coin['total_volume'] ?? 0),
                    'sparkline' => $sparkline
                ];
            }
            return $result;
        }
    }
    return null;
}

try {
    // Проверяем существование столбца sparkline
    $columnExists = false;
    try {
        $stmtCheck = $pdo->query("SHOW COLUMNS FROM cryptocurrencies LIKE 'sparkline'");
        if ($stmtCheck) {
            $columnExists = ($stmtCheck->rowCount() > 0);
        }
    } catch (Exception $e) {
        error_log("Column check error: " . $e->getMessage());
    }

    // Формируем запрос
    $query = "SELECT coin_id, ticker, name, price, change_24h, market_cap, volume_24h";
    
    if ($columnExists) {
        $query .= ", sparkline";
    }
    
    $query .= " FROM cryptocurrencies ORDER BY market_cap DESC LIMIT 50";
    
    $stmt = $pdo->query($query);
    $result = [];
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Обрабатываем sparkline
        $sparkline = [];
        if ($columnExists && !empty($row['sparkline'])) {
            $decoded = json_decode($row['sparkline'], true);
            
            if (is_array($decoded)) {
                $sparkline = $decoded;
            } else {
                // Если не удалось декодировать, обрабатываем как строку
                $sparkline = array_map('floatval', explode(',', $row['sparkline']));
            }
        }
        
        // Если данных нет, используем текущую цену
        if (empty($sparkline)) {
            $sparkline = array_fill(0, 7, $row['price'] ?? 0);
        }
        
        $result[] = [
            'coin_id' => $row['coin_id'],
            'ticker' => $row['ticker'],
            'name' => $row['name'],
            'price' => (float)$row['price'],
            'change_24h' => (float)$row['change_24h'],
            'market_cap' => (float)$row['market_cap'],
            'volume_24h' => (float)$row['volume_24h'],
            'sparkline' => $sparkline
        ];
    }
    
    // Если данных в БД нет, используем резервный файл
    if (empty($result)) {
        $fallbackResult = getFallbackData();
        if ($fallbackResult) {
            $result = $fallbackResult;
            error_log("Using fallback data from last_data.json for API");
        }
    }
    
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // Используем резервные данные
    $fallbackResult = getFallbackData();
    if ($fallbackResult) {
        echo json_encode($fallbackResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Если ничего не получилось
    http_response_code(500);
    echo json_encode([
        'error' => 'Не удалось получить данные',
        'solution' => 'Пожалуйста, запустите скрипт обновления вручную'
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
?>