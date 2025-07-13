<?php
require 'db_connect.php';

set_time_limit(400); // Увеличенный лимит времени

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Получаем API-ключ из переменных окружения
$apiKey = getenv('COINGECKO_API_KEY') ?: null;

// Функция для получения данных через прокси
function fetchWithProxy($url, $apiKey = null) {
    $proxies = [
        'https://api.allorigins.win/raw?url=',
        'https://corsproxy.io/?',
        'https://api.codetabs.com/v1/proxy?quest=',
        '' // Прямой запрос
    ];
    
    // Добавляем API-ключ к URL если он указан
    if ($apiKey) {
        $url .= (strpos($url, '?') === false ? '?' : '&');
        $url .= "x_cg_demo_api_key=$apiKey";
    }
    
    foreach ($proxies as $proxy) {
        try {
            $fullUrl = $proxy . $url;
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $fullUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 45,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Accept-Language: ru-RU,ru;q=0.9'
                ]
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                error_log("CURL error for proxy $proxy: " . curl_error($ch));
                curl_close($ch);
                continue;
            }
            
            curl_close($ch);
            
            if ($httpCode === 200 && !empty($response)) {
                return $response;
            } else {
                error_log("Proxy $proxy returned HTTP $httpCode for URL: $url");
            }
        } catch (Exception $e) {
            error_log("Proxy error for $proxy: " . $e->getMessage());
        }
        sleep(1);
    }
    return null;
}

// Функция для получения данных графиков
function fetchSparklineData($coinId, $apiKey = null, $retry = 3) {
    $url = "https://api.coingecko.com/api/v3/coins/$coinId/market_chart?vs_currency=usd&days=7";
    
    for ($attempt = 0; $attempt < $retry; $attempt++) {
        $response = fetchWithProxy($url, $apiKey);
        
        if ($response) {
            $data = json_decode($response, true);
            if (isset($data['prices']) && is_array($data['prices']) && count($data['prices']) > 0) {
                return $data['prices'];
            } else {
                $error = isset($data['status']['error_message']) ? $data['status']['error_message'] : substr($response, 0, 100);
                error_log("Invalid sparkline data for $coinId: " . $error);
            }
        }
        sleep(rand(5, 10)); // Увеличенная случайная задержка
    }
    return [];
}

// Основная функция получения данных
function fetchCryptoData($apiKey = null) {
    $url = 'https://api.coingecko.com/api/v3/coins/markets?vs_currency=usd&order=market_cap_desc&per_page=50&page=1';
    $response = fetchWithProxy($url, $apiKey);
    
    if (!$response) {
        error_log("Failed to fetch main data from CoinGecko");
        return null;
    }
    
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data)) {
        error_log("Invalid data from CoinGecko");
        return null;
    }
    
    $counter = 0;
    $totalCoins = count($data);
    
    foreach ($data as &$coin) {
        $counter++;
        $coinId = $coin['id'] ?? 'unknown';
        $coinName = $coin['name'] ?? 'Unknown';
        
        // Логирование прогресса
        error_log("Processing coin $counter/$totalCoins: $coinName ($coinId)");
        
        // Пауза между монетами
        if ($counter > 1) {
            sleep(rand(3, 6));
        }
        
        if ($coinId) {
            $prices = fetchSparklineData($coinId, $apiKey);
            
            if (!empty($prices)) {
                // Берем последние 7 точек
                $sparkline = [];
                $pricesCount = count($prices);
                $startIndex = max(0, $pricesCount - 7);
                
                for ($i = $startIndex; $i < $pricesCount; $i++) {
                    if (isset($prices[$i][1])) {
                        $sparkline[] = $prices[$i][1];
                    }
                }
                
                // Если данных меньше 7, дополняем последним значением
                $sparklineCount = count($sparkline);
                if ($sparklineCount < 7) {
                    $lastValue = $sparklineCount > 0 ? $sparkline[$sparklineCount-1] : ($coin['current_price'] ?? 0);
                    for ($i = $sparklineCount; $i < 7; $i++) {
                        $sparkline[] = $lastValue;
                    }
                }
                
                $coin['sparkline_in_7d'] = ['price' => $sparkline];
                error_log("Successfully fetched sparkline for: $coinName");
            } else {
                // Если данные не получены - создаем график из текущей цены
                $sparkline = array_fill(0, 7, $coin['current_price'] ?? 0);
                $coin['sparkline_in_7d'] = ['price' => $sparkline];
                error_log("Failed to fetch sparkline for: $coinName - using current price");
            }
        }
    }
    unset($coin);
    
    return $data;
}

// Альтернативный источник данных
function fetchAlternativeData() {
    $sources = [
        [
            'url' => 'https://api.coincap.io/v2/assets?limit=50',
            'parser' => function($response) {
                $data = json_decode($response, true)['data'] ?? [];
                $result = [];
                foreach ($data as $asset) {
                    $result[] = [
                        'id' => $asset['id'],
                        'symbol' => $asset['symbol'],
                        'name' => $asset['name'],
                        'current_price' => (float)($asset['priceUsd'] ?? 0),
                        'price_change_percentage_24h' => (float)($asset['changePercent24Hr'] ?? 0),
                        'market_cap' => (float)($asset['marketCapUsd'] ?? 0),
                        'total_volume' => (float)($asset['volumeUsd24Hr'] ?? 0),
                        'sparkline_in_7d' => ['price' => array_fill(0, 7, (float)($asset['priceUsd'] ?? 0))]
                    ];
                }
                return $result;
            }
        ],
        [
            'url' => 'https://api.coinlore.net/api/tickers/?limit=50',
            'parser' => function($response) {
                $data = json_decode($response, true)['data'] ?? [];
                $result = [];
                foreach ($data as $asset) {
                    $result[] = [
                        'id' => $asset['id'],
                        'symbol' => $asset['symbol'],
                        'name' => $asset['name'],
                        'current_price' => (float)($asset['price_usd'] ?? 0),
                        'price_change_percentage_24h' => (float)($asset['percent_change_24h'] ?? 0),
                        'market_cap' => (float)($asset['market_cap_usd'] ?? 0),
                        'total_volume' => (float)($asset['volume24'] ?? 0),
                        'sparkline_in_7d' => ['price' => array_fill(0, 7, (float)($asset['price_usd'] ?? 0))]
                    ];
                }
                return $result;
            }
        ],
        [
            'url' => 'https://api.coinpaprika.com/v1/tickers?quotes=USD&limit=50',
            'parser' => function($response) {
                $data = json_decode($response, true);
                $result = [];
                foreach ($data as $asset) {
                    $result[] = [
                        'id' => $asset['id'],
                        'symbol' => $asset['symbol'],
                        'name' => $asset['name'],
                        'current_price' => (float)($asset['quotes']['USD']['price'] ?? 0),
                        'price_change_percentage_24h' => (float)($asset['quotes']['USD']['percent_change_24h'] ?? 0),
                        'market_cap' => (float)($asset['quotes']['USD']['market_cap'] ?? 0),
                        'total_volume' => (float)($asset['quotes']['USD']['volume_24h'] ?? 0),
                        'sparkline_in_7d' => ['price' => array_fill(0, 7, (float)($asset['quotes']['USD']['price'] ?? 0))]
                    ];
                }
                return $result;
            }
        ]
    ];
    
    foreach ($sources as $source) {
        try {
            error_log("Trying alternative source: " . $source['url']);
            $ch = curl_init($source['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = $source['parser']($response);
                if (!empty($data)) {
                    error_log("Successfully fetched data from: " . $source['url']);
                    return $data;
                }
            } else {
                error_log("Source {$source['url']} returned HTTP $httpCode");
            }
        } catch (Exception $e) {
            error_log("Alternative source error: " . $e->getMessage());
        }
    }
    
    return null;
}

try {
    // Проверка и создание таблицы, если нужно
    $stmt = $pdo->query("SHOW TABLES LIKE 'cryptocurrencies'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("CREATE TABLE cryptocurrencies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coin_id VARCHAR(255) NOT NULL UNIQUE,
            ticker VARCHAR(10) NOT NULL,
            name VARCHAR(255) NOT NULL,
            price DECIMAL(20,8) NOT NULL,
            change_24h DECIMAL(10,4) NOT NULL,
            market_cap DECIMAL(20,2) NOT NULL,
            volume_24h DECIMAL(20,2) NOT NULL,
            sparkline TEXT,
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    }
    
    // Проверка существования столбца sparkline
    $stmt = $pdo->query("SHOW COLUMNS FROM cryptocurrencies LIKE 'sparkline'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE cryptocurrencies ADD COLUMN sparkline TEXT");
    }

    // Получаем данные (пробуем CoinGecko с ключом)
    $data = fetchCryptoData($apiKey);
    
    // Если данные не получены, используем альтернативные источники
    if (!$data || count($data) < 50) {
        error_log("Using alternative data sources");
        $data = fetchAlternativeData();
    }
    
    // Если все источники не дали данных
    if (!$data) {
        if (file_exists('last_data.json')) {
            $data = json_decode(file_get_contents('last_data.json'), true);
            error_log("Using fallback data from last_data.json");
        }
        
        if (!$data) {
            throw new Exception("Не удалось получить данные ни с одного источника");
        }
    }

    $stmt = $pdo->prepare("REPLACE INTO cryptocurrencies 
        (coin_id, ticker, name, price, change_24h, market_cap, volume_24h, sparkline) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    $count = 0;
    foreach ($data as $coin) {
        try {
            $count++;
            
            $coinId = $coin['id'] ?? 'unknown_' . $count;
            $ticker = strtoupper($coin['symbol'] ?? 'UNK');
            $name = $coin['name'] ?? 'Неизвестная монета';
            $price = $coin['current_price'] ?? 0;
            $change24h = $coin['price_change_percentage_24h'] ?? 0;
            $marketCap = $coin['market_cap'] ?? 0;
            $volume24h = $coin['total_volume'] ?? 0;
            
            // Формируем sparkline
            $sparkline = $coin['sparkline_in_7d']['price'] ?? array_fill(0, 7, $price);
            $sparklineJson = json_encode($sparkline);
            
            $stmt->execute([
                $coinId,
                $ticker,
                $name,
                $price,
                $change24h,
                $marketCap,
                $volume24h,
                $sparklineJson
            ]);
            
            error_log("Saved coin: $name ($ticker)");
        } catch (Exception $e) {
            error_log("Error processing coin {$coinId}: " . $e->getMessage());
        }
    }
    
    // Сохраняем для резервного использования
    file_put_contents('last_data.json', json_encode($data, JSON_UNESCAPED_UNICODE));
    echo "Данные успешно обновлены! Обработано $count монет.";

} catch (Exception $e) {
    error_log("[" . date('Y-m-d H:i:s') . "] Ошибка: " . $e->getMessage());
    echo "Ошибка: " . $e->getMessage() . "<br>";
    
    if (file_exists('last_data.json')) {
        $fallbackData = json_decode(file_get_contents('last_data.json'), true);
        
        if (is_array($fallbackData) && !empty($fallbackData)) {
            $stmt = $pdo->prepare("REPLACE INTO cryptocurrencies 
                (coin_id, ticker, name, price, change_24h, market_cap, volume_24h, sparkline) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($fallbackData as $coin) {
                $sparkline = $coin['sparkline_in_7d']['price'] ?? array_fill(0, 7, $coin['current_price'] ?? 0);
                $sparklineJson = json_encode($sparkline);
                
                $stmt->execute([
                    $coin['id'] ?? 'fallback',
                    strtoupper($coin['symbol'] ?? 'FALL'),
                    $coin['name'] ?? 'Резервные данные',
                    $coin['current_price'] ?? 0,
                    $coin['price_change_percentage_24h'] ?? 0,
                    $coin['market_cap'] ?? 0,
                    $coin['total_volume'] ?? 0,
                    $sparklineJson
                ]);
            }
            
            echo "<br>Использованы резервные данные.";
        }
    }
}
?>