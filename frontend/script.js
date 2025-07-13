$(document).ready(function() {
    // Секретный ключ для обновления
    const UPDATE_SECRET = "secure_crypto_key";
    
    // Обновление времени последнего обновления
    const updateLastUpdated = () => {
        const now = new Date();
        const formatted = now.toLocaleTimeString('ru-RU', {hour: '2-digit', minute:'2-digit'});
        $('#lastUpdated').html(`Последнее обновление: <strong>${formatted}</strong>`);
    };
    updateLastUpdated();

    // Инициализация таблицы
    const table = $('#cryptoTable').DataTable({
        "processing": true,
        "ajax": {
            "url": "backend/api_handler.php",
            "dataSrc": function(json) {
                if (json.error) {
                    console.error("Ошибка сервера:", json.error);
                    showNotification(json.error, 'error');
                    return [];
                }
                
                // Добавляем порядковый номер
                return json.map((item, index) => {
                    return {
                        index: index + 1,
                        ...item
                    };
                });
            },
            "error": function(xhr) {
                console.error("Ошибка API:", xhr.responseText);
                showNotification("Ошибка загрузки данных. См. консоль для деталей.", 'error');
                return [];
            }
        },
        "columns": [
            { 
                "data": "index",
                "className": "text-center",
                "orderable": false
            },
            { 
                "data": "ticker",
                "render": function(data) {
                    return `<strong>${data}</strong>`;
                }
            },
            { 
                "data": "name",
                "render": function(data) {
                    return data;
                }
            },
            {
                "data": "price",
                "render": function(data, type, row) {
                    return `<div class="price-value">$${formatNumber(data, 2, 8)}</div>`;
                }
            },
            {
                "data": "change_24h",
                "render": function(data) {
                    const value = parseFloat(data);
                    const isPositive = value >= 0;
                    const arrow = isPositive ? '▲' : '▼';
                    return `
                        <div class="${isPositive ? 'price-up' : 'price-down'}">
                            ${arrow} ${Math.abs(value).toFixed(2)}%
                        </div>
                    `;
                }
            },
            {
                "data": "market_cap",
                "render": function(data, type, row) {
                    // Для сортировки используем числовое значение
                    if (type === 'sort') return data;
                    return `<div>$${formatMarketCap(data)}</div>`;
                }
            },
            {
                "data": "volume_24h",
                "render": function(data, type, row) {
                    // Для сортировки используем числовое значение
                    if (type === 'sort') return data;
                    return `<div>$${formatNumber(data, 0)}</div>`;
                }
            },
            {
                "data": "sparkline",
                "render": function(data, type, row) {
                    // Убедимся, что data - это массив
                    if (!Array.isArray(data)) {
                        data = [];
                    }
                    return `
                        <div class="mini-chart">
                            <canvas class="chart-canvas" 
                                    data-points="${data.join(',')}"
                                    data-ticker="${row.ticker}"></canvas>
                        </div>
                    `;
                }
            }
        ],
        "order": [[5, 'desc']], // Сортировка по капитализации по убыванию
        "language": {
            "emptyTable": "Нет данных о криптовалютах. <button class='btn-refresh' onclick='location.reload()'>Обновить страницу</button>",
            "loadingRecords": "<i class='fas fa-spinner fa-spin'></i> Загрузка данных...",
            "processing": "<i class='fas fa-spinner fa-spin'></i> Обработка...",
            "info": "Показано _START_ - _END_ из _TOTAL_ записей",
            "infoEmpty": "Показано 0 - 0 из 0 записей",
            "infoFiltered": "(отфильтровано из _MAX_ записей)",
            "search": "Поиск:",
            "paginate": {
                "first": "Первая",
                "last": "Последняя",
                "next": "Следующая",
                "previous": "Предыдущая"
            }
        },
        "initComplete": function() {
            // Рендерим графики после инициализации таблицы
            setTimeout(renderMiniCharts, 500);
        }
    });
    
    // Форматирование чисел с разделителями
    function formatNumber(num, minDecimals = 2, maxDecimals = 8) {
        const number = parseFloat(num);
        if (isNaN(number)) return '0.00';
        
        return number.toLocaleString('ru-RU', {
            minimumFractionDigits: minDecimals,
            maximumFractionDigits: maxDecimals
        });
    }
    
    // Форматирование капитализации (млрд/млн)
    function formatMarketCap(value) {
        const number = parseFloat(value);
        if (isNaN(number)) return '$0';
        
        if (number >= 1e12) {
            return (number / 1e12).toFixed(3) + ' трлн';
        } else if (number >= 1e9) {
            return (number / 1e9).toFixed(3) + ' млрд';
        } else if (number >= 1e6) {
            return (number / 1e6).toFixed(3) + ' млн';
        }
        return formatNumber(number, 2);
    }
    
    // Функция для рисования плавных кривых Безье
    function drawSmoothCurve(ctx, points, width, height, isPositive) {
        ctx.beginPath();
        ctx.strokeStyle = isPositive ? '#2ecc71' : '#e74c3c';
        ctx.lineWidth = 2;
        
        const min = Math.min(...points);
        const max = Math.max(...points);
        const range = max - min || 1;
        
        // Преобразуем цены в координаты Y
        const yCoords = points.map(p => height - ((p - min) / range) * height);
        
        // Рассчитываем шаг по X
        const step = width / (points.length - 1);
        
        // Начинаем путь с первой точки
        ctx.moveTo(0, yCoords[0]);
        
        // Рисуем плавные кривые Безье между точками
        for (let i = 0; i < points.length - 1; i++) {
            const x0 = i * step;
            const y0 = yCoords[i];
            const x1 = (i + 1) * step;
            const y1 = yCoords[i + 1];
            
            // Контрольные точки для создания плавной кривой
            const cpX = x0 + (x1 - x0) / 2;
            const cpY0 = y0;
            const cpY1 = y1;
            
            // Рисуем кривую Безье с контрольными точками
            ctx.bezierCurveTo(
                cpX, cpY0,
                cpX, cpY1,
                x1, y1
            );
        }
        
        ctx.stroke();
        
        // Рисуем последнюю точку
        ctx.beginPath();
        ctx.fillStyle = isPositive ? '#2ecc71' : '#e74c3c';
        ctx.arc(width, yCoords[yCoords.length - 1], 3, 0, Math.PI * 2);
        ctx.fill();
    }
    
    // Функция отрисовки мини-графиков
    function renderMiniCharts() {
        $('.chart-canvas').each(function() {
            const points = $(this).data('points').split(',').map(Number);
            const ticker = $(this).data('ticker');
            const canvas = this;
            const ctx = canvas.getContext('2d');
            
            // Установка размеров
            const parent = $(canvas).closest('.mini-chart');
            const width = parent.width();
            const height = parent.height();
            canvas.width = width;
            canvas.height = height;
            
            ctx.clearRect(0, 0, width, height);
            
            if (points.length === 0 || points.every(p => isNaN(p))) {
                ctx.fillStyle = '#95a5a6';
                ctx.font = '10px Arial';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText('Нет данных', width/2, height/2);
                return;
            }

            const isPositive = points[points.length-1] >= points[0];
            
            // Рисуем плавный график
            drawSmoothCurve(ctx, points, width, height, isPositive);
        });
    }
    
    // Функция показа уведомлений
    function showNotification(message, type = 'info') {
        // Удаляем предыдущие уведомления
        $('.notification').remove();
        
        const $notification = $(`
            <div class="notification ${type}">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : 
                               type === 'error' ? 'fa-exclamation-circle' : 
                               'fa-info-circle'}"></i>
                ${message}
            </div>
        `).appendTo('body');
        
        // Анимация появления
        $notification.css({opacity: 0, bottom: '-50px'})
            .animate({opacity: 1, bottom: '20px'}, 300);
        
        // Автоматическое скрытие
        setTimeout(() => {
            $notification.animate({opacity: 0, bottom: '-50px'}, 300, () => {
                $notification.remove();
            });
        }, 3000);
    }
    
    // Кнопка обновления данных
    $('#refreshBtn').click(function() {
        const btn = $(this);
        btn.addClass('loading');
        btn.prop('disabled', true);
        
        // Показываем глобальный индикатор загрузки
        const loadingIndicator = $('<div id="global-loading" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center;">' +
            '<i class="fas fa-spinner fa-spin fa-3x" style="color:#fff;"></i>' +
        '</div>');
        $('body').append(loadingIndicator);
        
        // Создаем таймаут для обработки зависания
        const timeoutId = setTimeout(() => {
            btn.removeClass('loading');
            btn.prop('disabled', false);
            $('#global-loading').remove();
            showNotification('Обновление заняло слишком много времени. Попробуйте позже.', 'error');
        }, 30000); // 30 секунд таймаут
        
        // Вызываем trigger_update.php с секретным ключом
        fetch(`backend/trigger_update.php?secret=${UPDATE_SECRET}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Ошибка запуска обновления');
                }
                return response.json();
            })
            .then(data => {
                clearTimeout(timeoutId);
                
                if (data.error) {
                    throw new Error(data.error);
                }
                
                // Показываем уведомление, что обновление запущено
                showNotification('Обновление данных запущено. Это может занять несколько минут.', 'info');
                
                // Проверяем статус каждые 2 секунды
                const checkInterval = setInterval(() => {
                    table.ajax.reload(null, false); // Перезагружаем без сброса
                    
                    // Если данные изменились
                    if (table.rows().count() > 0) {
                        clearInterval(checkInterval);
                        
                        // Обновляем время
                        updateLastUpdated();
                        
                        // Перерисовываем графики
                        setTimeout(renderMiniCharts, 300);
                        
                        // Восстанавливаем кнопку
                        btn.removeClass('loading');
                        btn.prop('disabled', false);
                        $('#global-loading').remove();
                        
                        // Показываем уведомление
                        showNotification('Данные успешно обновлены!', 'success');
                    }
                }, 2000);
            })
            .catch(error => {
                clearTimeout(timeoutId);
                console.error('Ошибка обновления:', error);
                
                // Восстанавливаем кнопку
                btn.removeClass('loading');
                btn.prop('disabled', false);
                $('#global-loading').remove();
                
                // Показываем ошибку
                showNotification(`Ошибка при запуске обновления: ${error.message}`, 'error');
            });
    });
    
    // Перерисовка графиков при изменении данных таблицы
    table.on('draw.dt', function() {
        setTimeout(renderMiniCharts, 500);
    });
    
    // Перерисовка графиков при изменении размера окна
    $(window).resize(function() {
        setTimeout(renderMiniCharts, 300);
    });
    
    // Автоматическое обновление данных каждые 5 минут
    setInterval(() => {
        $('#refreshBtn').click();
    }, 300000); // 5 минут = 300000 миллисекунд
});