<?php
declare(strict_types=1);

namespace Beeline\ResilientMutex;

use Beeline\CircuitBreaker\CircuitBreaker;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\mutex\Mutex;

/**
 * mutex с паттерном Circuit Breaker и автоматическим переключением реализации
 *
 * Поддерживает настраиваемые бэкенды mutex с автоматическим переключением и восстановлением.
 * Каждый бэкенд имеет собственный circuit breaker для предотвращения каскадных сбоев.
 *
 * Пример конфигурации:
 * ```php
 * 'mutex' => [
 *     'class' => ResilientMutex::class,
 *     'backends' => [
 *         [
 *             'mutex' => ['class' => RedisMutex::class, 'redis' => 'redis'],
 *             'retries' => 3,
 *             'retryDelay' => 50, // миллисекунды
 *             'circuitBreaker' => [
 *                 'failureThreshold' => 0.5,
 *                 'windowSize' => 10,
 *                 'timeout' => 30,
 *             ],
 *         ],
 *         [
 *             'mutex' => ['class' => PgsqlMutex::class, 'db' => 'db'],
 *             'retries' => 2,
 *             'retryDelay' => 100,
 *             'circuitBreaker' => [
 *                 'failureThreshold' => 0.7,
 *                 'windowSize' => 5,
 *                 'timeout' => 60,
 *             ],
 *         ],
 *     ],
 *     'globalRetries' => 5,
 *     'retryStrategy' => ResilientMutex::RETRY_PER_BACKEND,
 * ],
 * ```
 */
class ResilientMutex extends Mutex
{
    /**
     * Повторы на бэкенд: пытаться N раз на каждом бэкенде перед переходом к следующему
     */
    public const string RETRY_PER_BACKEND = 'per_backend';

    /**
     * Глобальные повторы: всего N повторов по всем бэкендам
     */
    public const string RETRY_GLOBAL = 'global';

    /**
     * Конфигурации бэкендов mutex
     *
     * Формат:
     * [
     *     [
     *         'mutex' => Mutex|array,        // Экземпляр Mutex или конфигурация
     *         'retries' => int,              // Количество повторов для этого бэкенда
     *         'retryDelay' => int,           // Миллисекунды между повторами
     *         'circuitBreaker' => array|null, // Конфигурация circuit breaker
     *     ],
     *     ...
     * ]
     *
     * @var array
     */
    public array $backends = [];

    /**
     * Стратегия повторов: RETRY_PER_BACKEND или RETRY_GLOBAL
     */
    public string $retryStrategy = self::RETRY_PER_BACKEND;

    /**
     * Глобальный лимит повторов (используется только со стратегией RETRY_GLOBAL)
     */
    public int $globalRetries = 10;

    /**
     * Инициализированные бэкенды mutex
     *
     * @var array<int, array{mutex: Mutex, retries: int, retryDelay: int, breaker: CircuitBreaker}>
     */
    private array $initializedBackends = [];

    /**
     * Отслеживает, какой бэкенд использовался для получения каждой блокировки
     *
     * @var array<string, int> Карта имя блокировки => индекс бэкенда
     * @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection
     */
    private array $acquiredLocks = [];

    /**
     * @inheritdoc
     * @throws InvalidConfigException
     */
    public function init(): void
    {
        parent::init();

        if ([] === $this->backends) {
            throw new InvalidConfigException('At least one mutex backend must be configured.');
        }

        foreach ($this->backends as $index => $backendConfig) {
            if (!isset($backendConfig['mutex'])) {
                throw new InvalidConfigException("Backend $index must have 'mutex' configuration.");
            }

            // Инициализация экземпляра mutex
            $mutex = $backendConfig['mutex'];
            if (is_array($mutex)) {
                $mutex = Yii::createObject($mutex);
            }

            if (!$mutex instanceof Mutex) {
                throw new InvalidConfigException("Backend $index mutex must extend yii\\mutex\\Mutex.");
            }

            // Инициализация circuit breaker
            $breakerConfig = array_merge(
                [
                    'class' => CircuitBreaker::class,
                    'failureThreshold' => 0.5,
                    'windowSize' => 10,
                    'timeout' => 30,
                ],
                $backendConfig['circuitBreaker'] ?? []
            );

            $breaker = Yii::createObject($breakerConfig);

            $this->initializedBackends[] = [
                'mutex' => $mutex,
                'retries' => $backendConfig['retries'] ?? 1,
                'retryDelay' => $backendConfig['retryDelay'] ?? 50,
                'breaker' => $breaker,
            ];
        }
    }

    /**
     * @inheritdoc
     */
    protected function acquireLock($name, $timeout = 0): bool
    {
        if ($this->retryStrategy === self::RETRY_GLOBAL) {
            return $this->acquireLockGlobalRetry($name, $timeout);
        }

        return $this->acquireLockPerBackend($name, $timeout);
    }

    /**
     * @inheritdoc
     */
    protected function releaseLock($name): bool
    {
        // Освобождаем на том же бэкенде, который получил блокировку
        $backendIndex = $this->acquiredLocks[$name] ?? null;

        if (null === $backendIndex) {
            Yii::warning("No backend found for lock '$name', attempting release on all backends", __METHOD__);
            return $this->releaseOnAllBackends($name);
        }

        $backend = $this->initializedBackends[$backendIndex];
        /** @var Mutex $mutex */
        $mutex = $backend['mutex'];
        /** @var CircuitBreaker $breaker */
        $breaker = $backend['breaker'];

        try {
            $released = $mutex->release($name);
            $breaker->recordSuccess();

            if ($released) {
                unset($this->acquiredLocks[$name]);
                Yii::debug("Released lock '$name' on backend $backendIndex", __METHOD__);
            }

            return $released;
        } catch (Throwable $e) {
            $breaker->recordFailure();
            Yii::error("Failed to release lock '$name' on backend $backendIndex: {$e->getMessage()}", __METHOD__);
            unset($this->acquiredLocks[$name]);
            return false;
        }
    }

    /**
     * Получить состояние здоровья всех бэкендов
     *
     * @return array<int, array{index: int, class: string, state: string, stats: array}>
     */
    public function getBackendStatus(): array
    {
        $status = [];

        foreach ($this->initializedBackends as $index => $backend) {
            $status[] = [
                'index' => $index,
                'class' => get_class($backend['mutex']),
                'state' => $backend['breaker']->getState(),
                'stats' => $backend['breaker']->getStats(),
            ];
        }

        return $status;
    }

    /**
     * Получить текущие захваченные блокировки
     *
     * @return array<string, int> Карта имя блокировки => индекс бэкенда
     */
    public function getAcquiredLocks(): array
    {
        return $this->acquiredLocks;
    }

    /**
     * Принудительно открыть circuit breaker бэкенда (полезно для тестирования)
     *
     * @param int $backendIndex
     */
    public function forceBackendOpen(int $backendIndex): void
    {
        if (isset($this->initializedBackends[$backendIndex])) {
            $this->initializedBackends[$backendIndex]['breaker']->forceOpen();
        }
    }

    /**
     * Принудительно закрыть circuit breaker бэкенда (полезно для тестирования)
     *
     * @param int $backendIndex
     */
    public function forceBackendClose(int $backendIndex): void
    {
        if (isset($this->initializedBackends[$backendIndex])) {
            $this->initializedBackends[$backendIndex]['breaker']->forceClose();
        }
    }

    /**
     * Сбросить все circuit breakers (полезно для тестирования)
     */
    public function resetCircuitBreakers(): void
    {
        foreach ($this->initializedBackends as $backend) {
            $backend['breaker']->reset();
        }
    }

    /**
     * Получить блокировку используя стратегию повторов на бэкенд
     */
    private function acquireLockPerBackend($name, $timeout): bool
    {
        foreach ($this->initializedBackends as $backendIndex => $backend) {
            /** @var Mutex $mutex */
            $mutex = $backend['mutex'];
            /** @var CircuitBreaker $breaker */
            $breaker = $backend['breaker'];
            $retries = $backend['retries'];
            $retryDelay = $backend['retryDelay'];

            // Пропускаем, если цепь открыта
            if (!$breaker->allowsRequest()) {
                Yii::debug("Backend $backendIndex circuit is open, skipping", __METHOD__);
                continue;
            }

            // Пытаемся получить блокировку с повторами
            for ($attempt = 0; $attempt < $retries; $attempt++) {
                try {
                    if ($mutex->acquire($name, $timeout)) {
                        $breaker->recordSuccess();
                        $this->acquiredLocks[$name] = $backendIndex;
                        Yii::debug("Acquired lock '$name' on backend $backendIndex (attempt " . ($attempt + 1) . ")", __METHOD__);
                        return true;
                    }

                    // Блокировка удерживается другим процессом, повторяем
                    if ($attempt < $retries - 1) {
                        usleep($retryDelay * 1000);
                    }
                } catch (Throwable $e) {
                    $breaker->recordFailure();
                    Yii::warning("Backend $backendIndex failed to acquire lock '$name' (attempt " . ($attempt + 1) . "): {$e->getMessage()}", __METHOD__);

                    // Переходим к следующему бэкенду при ошибке
                    break;
                }
            }
        }

        Yii::error("Failed to acquire lock '$name' on all backends", __METHOD__);
        return false;
    }

    /**
     * Получить блокировку используя стратегию глобальных повторов
     */
    private function acquireLockGlobalRetry($name, $timeout): bool
    {
        $totalAttempts = 0;

        while ($totalAttempts < $this->globalRetries) {
            foreach ($this->initializedBackends as $backendIndex => $backend) {
                /** @var Mutex $mutex */
                $mutex = $backend['mutex'];
                /** @var CircuitBreaker $breaker */
                $breaker = $backend['breaker'];
                $retryDelay = $backend['retryDelay'];

                // Пропускаем, если цепь открыта
                if (!$breaker->allowsRequest()) {
                    continue;
                }

                try {
                    $breaker->recordSuccess();
                    if ($mutex->acquire($name, $timeout)) {
                        $this->acquiredLocks[$name] = $backendIndex;
                        Yii::debug("Acquired lock '$name' on backend $backendIndex (global attempt " . ($totalAttempts + 1) . ")", __METHOD__);
                        return true;
                    }

                    // Блокировка удерживается другим процессом
                    // Нет ошибки, просто заблокирована
                } catch (Throwable $e) {
                    $breaker->recordFailure();
                    Yii::warning("Backend $backendIndex failed (global attempt " . ($totalAttempts + 1) . "): {$e->getMessage()}", __METHOD__);
                }

                $totalAttempts++;

                if ($totalAttempts >= $this->globalRetries) {
                    break 2; // Прерываем внешний цикл
                }

                usleep($retryDelay * 1000);
            }
        }

        Yii::error("Failed to acquire lock '$name' after $totalAttempts global attempts", __METHOD__);
        return false;
    }

    /**
     * Попытаться освободить блокировку на всех бэкендах (резервный вариант когда бэкенд неизвестен)
     */
    private function releaseOnAllBackends($name): bool
    {
        $anySuccess = false;

        foreach ($this->initializedBackends as $backendIndex => $backend) {
            /** @var Mutex $mutex */
            $mutex = $backend['mutex'];

            try {
                if ($mutex->release($name)) {
                    $anySuccess = true;
                    Yii::debug("Released lock '$name' on backend $backendIndex", __METHOD__);
                }
            } catch (Throwable $e) {
                Yii::warning("Failed to release lock '$name' on backend $backendIndex: {$e->getMessage()}", __METHOD__);
            }
        }

        unset($this->acquiredLocks[$name]);
        return $anySuccess;
    }
}
