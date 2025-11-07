# Отказоустойчивый Mutex для Yii2

[![Tests](https://github.com/pozitronik/yii2-resilient-mutex/actions/workflows/tests.yml/badge.svg)](https://github.com/pozitronik/yii2-resilient-mutex/actions/workflows/tests.yml)
[![Codecov](https://codecov.io/gh/pozitronik/yii2-resilient-mutex/branch/master/graph/badge.svg)](https://codecov.io/gh/pozitronik/yii2-resilient-mutex)
[![Packagist Version](https://img.shields.io/packagist/v/beeline/yii2-resilient-mutex)](https://packagist.org/packages/beeline/yii2-resilient-mutex)
[![Packagist License](https://img.shields.io/packagist/l/beeline/yii2-resilient-mutex)](https://packagist.org/packages/beeline/yii2-resilient-mutex)
[![Packagist Downloads](https://img.shields.io/packagist/dt/beeline/yii2-resilient-mutex)](https://packagist.org/packages/beeline/yii2-resilient-mutex)

Отказоустойчивый mutex с паттерном Circuit Breaker и автоматическим переключением между бэкендами для Yii2. Обеспечивает высокую доступность распределённых блокировок.

## Возможности

- **Автоматическое переключение между бэкендами**: Бесшовный переход на резервные механизмы блокировок при сбоях
- **Интеграция Circuit Breaker**: Каждый бэкенд имеет собственный circuit breaker для предотвращения каскадных сбоев
- **Две стратегии повторов**: RETRY_PER_BACKEND и RETRY_GLOBAL для различных сценариев
- **Отслеживание блокировок**: Запоминает, какой бэкенд захватил каждую блокировку для корректного освобождения
- **Мониторинг работоспособности**: Информация о состоянии и статистике бэкендов в реальном времени

## Установка

```bash
composer require beeline/yii2-resilient-mutex
```

## Требования

- PHP >= 8.4
- Yii2 >= 2.0.45
- beeline/yii2-circuit-breaker >= 1.0

## Использование

### Базовая конфигурация

```php
use beeline\ResilientMutex\ResilientMutex;
use yii\redis\Mutex as RedisMutex;
use yii\mutex\PgsqlMutex;

return [
    'components' => [
        'mutex' => [
            'class' => ResilientMutex::class,
            'backends' => [
                // Первичный бэкенд: Redis
                [
                    'mutex' => [
                        'class' => RedisMutex::class,
                        'redis' => 'redis',
                    ],
                    'retries' => 3,
                    'retryDelay' => 50, // миллисекунды
                    'circuitBreaker' => [
                        'failureThreshold' => 0.5,
                        'windowSize' => 10,
                        'timeout' => 30,
                    ],
                ],
                // Резервный бэкенд: PostgreSQL
                [
                    'mutex' => [
                        'class' => PgsqlMutex::class,
                        'db' => 'db',
                    ],
                    'retries' => 2,
                    'retryDelay' => 100,
                    'circuitBreaker' => [
                        'failureThreshold' => 0.7,
                        'windowSize' => 5,
                        'timeout' => 60,
                    ],
                ],
            ],
            'retryStrategy' => ResilientMutex::RETRY_PER_BACKEND,
        ],
    ],
];
```

### Простое использование

```php
use Yii;

$mutex = Yii::$app->mutex;

// Автоматическое переключение между бэкендами
if ($mutex->acquire('my_lock', 5)) {
    try {
        // Критическая секция
        performCriticalOperation();
    } finally {
        $mutex->release('my_lock');
    }
}
```

## Конфигурация

### Параметры бэкенда

| Параметр | Тип | Описание |
|----------|-----|----------|
| `mutex` | Mutex\|array | Экземпляр Mutex или конфигурация для создания |
| `retries` | int | Количество повторов для этого бэкенда |
| `retryDelay` | int | Миллисекунды между повторами |
| `circuitBreaker` | array\|null | Конфигурация circuit breaker для этого бэкенда |

### Стратегии повторов

#### RETRY_PER_BACKEND (по умолчанию)

Пытается N раз на каждом бэкенде перед переходом к следующему:

```php
'retryStrategy' => ResilientMutex::RETRY_PER_BACKEND,
'backends' => [
    ['mutex' => [...], 'retries' => 3],  // 3 попытки на Redis
    ['mutex' => [...], 'retries' => 2],  // 2 попытки на PostgreSQL
],
```

#### RETRY_GLOBAL

Всего N попыток по всем бэкендам:

```php
'retryStrategy' => ResilientMutex::RETRY_GLOBAL,
'globalRetries' => 10,  // Всего 10 попыток на все бэкенды
```

## Мониторинг

### Проверка состояния бэкендов

```php
$mutex = Yii::$app->mutex;

// Получить состояние всех бэкендов
$status = $mutex->getBackendStatus();

foreach ($status as $backend) {
    echo "Бэкенд #{$backend['index']}: {$backend['class']}\n";
    echo "Состояние: {$backend['state']}\n"; // closed, open, half_open
    echo "Отказов: {$backend['stats']['failures']}/{$backend['stats']['total']}\n";
    echo "Частота отказов: " . ($backend['stats']['failureRate'] * 100) . "%\n\n";
}
```

### Отслеживание захваченных блокировок

```php
// Узнать, какие блокировки захвачены и какими бэкендами
$locks = $mutex->getAcquiredLocks();

foreach ($locks as $name => $backendIndex) {
    echo "Блокировка '$name' захвачена бэкендом #{$backendIndex}\n";
}
```

## Расширенное использование

### Ручное управление Circuit Breakers

```php
// Принудительно открыть circuit breaker бэкенда (для тестирования)
$mutex->forceBackendOpen(0);

// Принудительно закрыть circuit breaker бэкенда
$mutex->forceBackendClose(0);

// Сбросить все circuit breakers
$mutex->resetCircuitBreakers();
```

### Алертинг при сбоях

```php
use beeline\ResilientMutex\ResilientMutex;

class MonitoredMutex extends ResilientMutex
{
    public function acquire($name, $timeout = 0): bool
    {
        $result = parent::acquire($name, $timeout);

        // Проверяем состояние бэкендов
        $status = $this->getBackendStatus();

        foreach ($status as $backend) {
            if ($backend['state'] === 'open') {
                // Отправляем алерт
                $this->sendAlert("Бэкенд {$backend['class']} недоступен!");
            }
        }

        return $result;
    }

    private function sendAlert(string $message): void
    {
        // Интеграция с вашей системой мониторинга
        Yii::error($message, __METHOD__);
    }
}
```

## Рекомендуемые конфигурации

### Высокая доступность (Redis + PostgreSQL)

```php
'backends' => [
    // Быстрый Redis для нормальной работы
    [
        'mutex' => ['class' => RedisMutex::class, 'redis' => 'redis'],
        'retries' => 3,
        'retryDelay' => 50,
        'circuitBreaker' => [
            'failureThreshold' => 0.5,
            'windowSize' => 20,
            'timeout' => 30,
        ],
    ],
    // Надёжный PostgreSQL как резерв
    [
        'mutex' => ['class' => PgsqlMutex::class, 'db' => 'db'],
        'retries' => 2,
        'retryDelay' => 100,
        'circuitBreaker' => [
            'failureThreshold' => 0.7,
            'windowSize' => 10,
            'timeout' => 60,
        ],
    ],
],
```

### Максимальная отказоустойчивость (три бэкенда)

```php
'backends' => [
    // Redis
    [
        'mutex' => ['class' => RedisMutex::class, 'redis' => 'redis'],
        'retries' => 2,
        'retryDelay' => 50,
    ],
    // PostgreSQL Advisory Locks
    [
        'mutex' => [
            'class' => \beeline\PgsqlAdvisoryMutex\PgsqlAdvisoryMutex::class,
            'db' => 'db',
        ],
        'retries' => 2,
        'retryDelay' => 75,
    ],
    // Файловые блокировки (последний резерв)
    [
        'mutex' => [
            'class' => \yii\mutex\FileMutex::class,
            'mutexPath' => '@runtime/mutex',
        ],
        'retries' => 1,
        'retryDelay' => 100,
    ],
],
```

### Быстрый failover (Redis + File)

```php
'backends' => [
    [
        'mutex' => ['class' => RedisMutex::class, 'redis' => 'redis'],
        'retries' => 1,  // Одна попытка
        'retryDelay' => 10,  // Быстрый переход
        'circuitBreaker' => [
            'failureThreshold' => 0.3,  // Быстрое открытие
            'timeout' => 10,  // Быстрое восстановление
        ],
    ],
    [
        'mutex' => ['class' => FileMutex::class],
        'retries' => 1,
        'retryDelay' => 0,
    ],
],
'retryStrategy' => ResilientMutex::RETRY_GLOBAL,
'globalRetries' => 3,
```

## Примеры использования

### Обработка критических задач

```php
use Yii;

class CriticalTaskProcessor
{
    public function processTask(int $taskId): void
    {
        $mutex = Yii::$app->mutex;
        $lockName = "task:{$taskId}";

        if (!$mutex->acquire($lockName, 10)) {
            throw new \RuntimeException("Не удалось захватить блокировку для задачи {$taskId}");
        }

        try {
            // Обработка задачи
            $this->performProcessing($taskId);

            // Проверяем, не было ли проблем с бэкендами
            $status = $mutex->getBackendStatus();
            $primaryState = $status[0]['state'] ?? 'unknown';

            if ($primaryState !== 'closed') {
                Yii::warning("Задача {$taskId} обработана, но основной mutex бэкенд в состоянии: {$primaryState}");
            }
        } finally {
            $mutex->release($lockName);
        }
    }
}
```

### Координация микросервисов

```php
use beeline\ResilientMutex\ResilientMutex;

class DistributedService
{
    private ResilientMutex $mutex;

    public function __construct()
    {
        $this->mutex = new ResilientMutex([
            'backends' => [
                [
                    'mutex' => ['class' => RedisMutex::class, 'redis' => 'redis'],
                    'retries' => 3,
                ],
                [
                    'mutex' => ['class' => PgsqlMutex::class, 'db' => 'db'],
                    'retries' => 2,
                ],
            ],
        ]);
    }

    public function synchronizeOperation(string $operationId): mixed
    {
        $lockName = "operation:{$operationId}";

        if ($this->mutex->acquire($lockName, 30)) {
            try {
                return $this->executeOperation($operationId);
            } finally {
                $this->mutex->release($lockName);
            }
        }

        throw new \RuntimeException('Операция уже выполняется другим сервисом');
    }

    public function getSystemHealth(): array
    {
        return [
            'mutex_backends' => $this->mutex->getBackendStatus(),
            'acquired_locks' => count($this->mutex->getAcquiredLocks()),
        ];
    }
}
```

## Диаграмма работы

```
Запрос блокировки
       ↓
┌──────────────────┐
│ Бэкенд 1 (Redis) │
└──────────────────┘
       ↓
   Успех? ──→ Да ──→ Возврат блокировки
       ↓
      Нет
       ↓
Circuit Breaker открыт? ──→ Да ──→ Пропуск бэкенда
       ↓
      Нет
       ↓
Повтор N раз
       ↓
   Неудача
       ↓
┌─────────────────────────┐
│ Бэкенд 2 (PostgreSQL)   │
└─────────────────────────┘
       ↓
   Успех? ──→ Да ──→ Возврат блокировки
       ↓
      Нет
       ↓
Повтор N раз
       ↓
   Неудача
       ↓
Возврат false
```

## Тестирование

```bash
# Запуск тестов
vendor/bin/phpunit

# Запуск с покрытием
vendor/bin/phpunit --coverage-html coverage/
```

## Производительность

### Накладные расходы

- **Circuit Breaker проверка**: ~0.01мс
- **Переключение между бэкендами**: ~0.1-1мс (зависит от бэкенда)
- **Память**: ~1KB на бэкенд для circuit breaker

### Рекомендации

1. **Используйте быстрые бэкенды первыми**: Redis → PostgreSQL → File
2. **Настраивайте агрессивные circuit breakers для ненадёжных бэкендов**
3. **Мониторьте состояние бэкендов** через `getBackendStatus()`
4. **Используйте RETRY_PER_BACKEND для критичных операций**
5. **Используйте RETRY_GLOBAL для быстрого failover**

## Отладка

### Включение подробного логирования

```php
'mutex' => [
    'class' => ResilientMutex::class,
    'backends' => [...],
    'on beforeAcquire' => function ($event) {
        Yii::debug("Попытка захвата блокировки: {$event->name}");
    },
    'on afterAcquire' => function ($event) {
        Yii::info("Блокировка захвачена: {$event->name} на бэкенде {$event->backend}");
    },
    'on backendFailure' => function ($event) {
        Yii::warning("Бэкенд {$event->backend} отказал: {$event->error}");
    },
],
```

### Мониторинг в production

```php
// Периодически проверяйте состояние
$status = $mutex->getBackendStatus();

foreach ($status as $backend) {
    // Отправляйте метрики в систему мониторинга
    $metrics->gauge('mutex.backend.failure_rate', $backend['stats']['failureRate'], [
        'backend' => $backend['class'],
        'index' => $backend['index'],
    ]);

    if ($backend['state'] === 'open') {
        $metrics->increment('mutex.backend.circuit_open', [
            'backend' => $backend['class'],
        ]);
    }
}
```

## Лицензия

GNU GPLv3.