<?php
/** @noinspection PhpRedundantOptionalArgumentInspection */
declare(strict_types=1);

namespace Beeline\ResilientMutex\Tests\Unit;

use Beeline\CircuitBreaker\BreakerInterface;
use Beeline\ResilientMutex\ResilientMutex;
use Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use stdClass;
use yii\base\InvalidConfigException;
use yii\mutex\Mutex;

/**
 * Тесты для отказоустойчивого ResilientMutex
 */
class ResilientMutexTest extends TestCase
{
    /**
     * Проверка корректной инициализации компонента с валидной конфигурацией
     */
    public function testInitWithValidConfig(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => new MockMutex()],
                ['mutex' => new MockMutex()],
            ],
        ]);

        $status = $mutex->getBackendStatus();

        self::assertCount(2, $status, 'Должно быть ровно 2 бэкенда');
        self::assertEquals(BreakerInterface::STATE_CLOSED, $status[0]['state']);
        self::assertEquals(BreakerInterface::STATE_CLOSED, $status[1]['state']);
    }

    /**
     * Проверка выброса исключения при инициализации без бэкендов
     */
    public function testInitThrowsExceptionWithEmptyBackends(): void
    {
        $this->expectException(InvalidConfigException::class);
        new ResilientMutex(['backends' => []]);
    }

    /**
     * Проверка захвата блокировки на первом бэкенде при стратегии повторов на каждом бэкенде
     */
    public function testAcquireOnFirstBackendPerBackend(): void
    {
        $b1 = new MockMutex();
        $b2 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 1],
                ['mutex' => $b2, 'retries' => 1],
            ],
            'retryStrategy' => ResilientMutex::RETRY_PER_BACKEND,
        ]);

        $result = $mutex->acquire('test_lock', 5);

        self::assertTrue($result);
        self::assertTrue($b1->isLocked('test_lock'));
        self::assertFalse($b2->isLocked('test_lock'));
    }

    /**
     * Проверка автоматического переключения на второй бэкенд при недоступности первого
     */
    public function testAcquireFallsBackToSecondBackend(): void
    {
        $b1 = new FailingMutex();
        $b2 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 2],
                ['mutex' => $b2, 'retries' => 1],
            ],
            'retryStrategy' => ResilientMutex::RETRY_PER_BACKEND,
        ]);

        $result = $mutex->acquire('test_lock', 5);

        self::assertTrue($result);
        self::assertTrue($b2->isLocked('test_lock'));
    }

    /**
     * Проверка механизма повторных попыток захвата на каждом бэкенде
     */
    public function testAcquireRetriesPerBackend(): void
    {
        $b1 = new CountingMutex(3); // Отказывает 3 раза, затем успешно захватывает

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 5, 'retryDelay' => 1],
            ],
            'retryStrategy' => ResilientMutex::RETRY_PER_BACKEND,
        ]);

        $result = $mutex->acquire('test_lock', 5);

        self::assertTrue($result);
        self::assertEquals(4, $b1->attempts); // 3 неудачи + 1 успех
    }

    /**
     * Проверка работы глобальной стратегии повторов по всем бэкендам
     */
    public function testAcquireWithGlobalRetryStrategy(): void
    {
        $b1 = new FailingMutex();
        $b2 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retryDelay' => 1],
                ['mutex' => $b2, 'retryDelay' => 1],
            ],
            'retryStrategy' => ResilientMutex::RETRY_GLOBAL,
            'globalRetries' => 5,
        ]);

        $result = $mutex->acquire('test_lock', 5);

        self::assertTrue($result);
        self::assertTrue($b2->isLocked('test_lock'));
    }

    /**
     * Проверка освобождения блокировки на том же бэкенде, где она была захвачена
     */
    public function testReleaseOnSameBackend(): void
    {
        $b1 = new MockMutex();
        $b2 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 1],
                ['mutex' => $b2, 'retries' => 1],
            ],
        ]);

        $mutex->acquire('test_lock', 5);
        self::assertTrue($b1->isLocked('test_lock'));

        $mutex->release('test_lock');
        self::assertFalse($b1->isLocked('test_lock'));
        self::assertFalse($b2->isLocked('test_lock'));
    }

    /**
     * Проверка освобождения блокировки на резервном бэкенде
     */
    public function testReleaseOnFallbackBackend(): void
    {
        $b1 = new FailingMutex();
        $b2 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 1],
                ['mutex' => $b2, 'retries' => 1],
            ],
        ]);

        $mutex->acquire('test_lock', 5);
        self::assertTrue($b2->isLocked('test_lock'));

        $mutex->release('test_lock');
        self::assertFalse($b2->isLocked('test_lock'));
    }

    /**
     * Проверка открытия circuit breaker при серии отказов
     */
    public function testCircuitBreakerOpensOnFailures(): void
    {
        $b1 = new FailingMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                [
                    'mutex' => $b1,
                    'retries' => 1,
                    'circuitBreaker' => [
                        'failureThreshold' => 0.5,
                        'windowSize' => 10,
                    ],
                ],
            ],
        ]);

        // Провоцируем отказы для открытия цепи
        for ($i = 0; $i < 10; $i++) {
            $mutex->acquire('lock_' . $i, 1);
        }

        $status = $mutex->getBackendStatus();
        self::assertEquals(BreakerInterface::STATE_OPEN, $status[0]['state']);
    }

    /**
     * Проверка блокировки запросов при открытом circuit breaker
     */
    public function testRequestsBlockedWhenCircuitOpen(): void
    {
        $b1 = new CountingMutex(10); // Будет отказывать первые 10 раз

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 1],
            ],
        ]);

        // Принудительно открываем цепь
        $mutex->forceBackendOpen(0);

        // Проверяем, что цепь открыта
        $status = $mutex->getBackendStatus();
        self::assertEquals(BreakerInterface::STATE_OPEN, $status[0]['state'], 'Цепь должна быть открыта');

        // Попытка захвата - должна провалиться без вызова бэкенда
        $result = $mutex->acquire('test_lock', 5);

        // Должна провалиться попытка захвата блокировки
        self::assertFalse($result, 'Должна провалиться попытка захвата при открытых цепях');
        // Бэкенд не должен активно вызываться при открытой цепи
        self::assertLessThanOrEqual(1, $b1->attempts, 'Бэкенд не должен активно использоваться при открытой цепи');
    }

    /**
     * Проверка отслеживания захваченных блокировок
     */
    public function testGetAcquiredLocksTracks(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => new MockMutex(), 'retries' => 1],
            ],
        ]);

        $mutex->acquire('lock1', 5);
        $mutex->acquire('lock2', 5);

        $locks = $mutex->getAcquiredLocks();

        self::assertArrayHasKey('lock1', $locks);
        self::assertArrayHasKey('lock2', $locks);
        self::assertEquals(0, $locks['lock1']); // Индекс бэкенда
        self::assertEquals(0, $locks['lock2']);
    }

    /**
     * Проверка очистки захваченных блокировок после освобождения
     */
    public function testAcquiredLocksCleanedUpAfterRelease(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => new MockMutex(), 'retries' => 1],
            ],
        ]);

        $mutex->acquire('test_lock', 5);
        self::assertNotEmpty($mutex->getAcquiredLocks());

        $mutex->release('test_lock');
        self::assertEmpty($mutex->getAcquiredLocks());
    }

    /**
     * Проверка сброса состояния circuit breakers всех бэкендов
     */
    public function testResetCircuitBreakers(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => new FailingMutex(), 'retries' => 1],
                ['mutex' => new FailingMutex(), 'retries' => 1],
            ],
        ]);

        // Открываем цепи
        for ($i = 0; $i < 10; $i++) {
            $mutex->acquire('lock_' . $i, 1);
        }

        $statusBefore = $mutex->getBackendStatus();
        self::assertEquals(BreakerInterface::STATE_OPEN, $statusBefore[0]['state']);

        // Сбрасываем
        $mutex->resetCircuitBreakers();

        $statusAfter = $mutex->getBackendStatus();
        self::assertEquals(BreakerInterface::STATE_CLOSED, $statusAfter[0]['state']);
    }

    /**
     * Проверка принудительного открытия circuit breaker бэкенда
     */
    public function testForceBackendOpen(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => new MockMutex(), 'retries' => 1],
            ],
        ]);

        $mutex->forceBackendOpen(0);

        $status = $mutex->getBackendStatus();
        self::assertEquals(BreakerInterface::STATE_OPEN, $status[0]['state']);
    }

    /**
     * Проверка принудительного закрытия circuit breaker бэкенда
     */
    public function testForceBackendClose(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => new FailingMutex(), 'retries' => 1],
            ],
        ]);

        // Открываем цепь
        for ($i = 0; $i < 10; $i++) {
            $mutex->acquire('lock_' . $i, 1);
        }

        $mutex->forceBackendClose(0);

        $status = $mutex->getBackendStatus();
        self::assertEquals(BreakerInterface::STATE_CLOSED, $status[0]['state']);
    }

    /**
     * Проверка получения детальной информации о состоянии бэкендов
     */
    public function testGetBackendStatusReturnsDetailedInfo(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => new MockMutex(), 'retries' => 1],
                ['mutex' => new MockMutex(), 'retries' => 1],
            ],
        ]);

        $status = $mutex->getBackendStatus();

        self::assertCount(2, $status, 'Должно быть настроено 2 бэкенда');
        self::assertArrayHasKey('index', $status[0]);
        self::assertArrayHasKey('class', $status[0]);
        self::assertArrayHasKey('state', $status[0]);
        self::assertArrayHasKey('stats', $status[0]);
        self::assertEquals(MockMutex::class, $status[0]['class']);
    }

    /**
     * Проверка возврата false при недоступности всех бэкендов
     */
    public function testAcquireReturnsFalseWhenAllBackendsFail(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => new FailingMutex(), 'retries' => 2],
                ['mutex' => new FailingMutex(), 'retries' => 2],
            ],
        ]);

        $result = $mutex->acquire('test_lock', 5);

        self::assertFalse($result);
    }

    /**
     * Проверка возможности одновременного захвата множественных блокировок
     */
    public function testMultipleLocksCanBeAcquired(): void
    {
        $b1 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 1],
            ],
        ]);

        $result1 = $mutex->acquire('lock1', 5);
        $result2 = $mutex->acquire('lock2', 5);

        self::assertTrue($result1);
        self::assertTrue($result2);
        self::assertTrue($b1->isLocked('lock1'));
        self::assertTrue($b1->isLocked('lock2'));
    }

    /**
     * Проверка выброса исключения при отсутствии ключа 'mutex' в конфигурации бэкенда
     */
    public function testInitThrowsExceptionWhenMutexKeyMissing(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("Backend 0 must have 'mutex' configuration");

        new ResilientMutex([
            'backends' => [
                ['retries' => 1], // 'mutex' отсутствует
            ],
        ]);
    }

    /**
     * Проверка инициализации с конфигурацией mutex в виде массива
     */
    public function testInitWithMutexAsArrayConfig(): void
    {
        $mutex = new ResilientMutex([
            'backends' => [
                [
                    'mutex' => ['class' => MockMutex::class],
                    'retries' => 1,
                ],
            ],
        ]);

        $status = $mutex->getBackendStatus();

        self::assertCount(1, $status);
        self::assertEquals(MockMutex::class, $status[0]['class']);
    }

    /**
     * Проверка выброса исключения когда mutex не является экземпляром Mutex
     */
    public function testInitThrowsExceptionWhenMutexNotInstance(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage("Backend 0 mutex must extend yii\\mutex\\Mutex");

        new ResilientMutex([
            'backends' => [
                ['mutex' => new stdClass()], // Не экземпляр Mutex
            ],
        ]);
    }

    /**
     * Проверка освобождения блокировки с неизвестным бэкендом (вызывает releaseOnAllBackends)
     *
     * Сценарий: если индекс бэкенда для блокировки неизвестен (был удалён из внутреннего
     * массива или не был записан), метод release должен попробовать освободить блокировку
     * на всех бэкендах и вернуть true, если хотя бы один бэкенд успешно освободил.
     */
    public function testReleaseWithUnknownBackend(): void
    {
        $b1 = new MockMutex();
        $b2 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 1],
                ['mutex' => $b2, 'retries' => 1],
            ],
        ]);

        // Захватываем блокировку на первом бэкенде
        $mutex->acquire('test_lock', 5);
        self::assertTrue($b1->isLocked('test_lock'));

        // Используем рефлексию для удаления записи о бэкенде (имитация потери информации)
        $reflection = new ReflectionClass($mutex);
        $property = $reflection->getProperty('acquiredLocks');
        $property->setValue($mutex, []); // Очищаем информацию о захваченных блокировках

        // Попытка освободить должна пройти по всем бэкендам и найти блокировку
        $result = $mutex->release('test_lock');

        self::assertTrue($result, 'Должна успешно освободить блокировку на резервном пути');
        self::assertFalse($b1->isLocked('test_lock'), 'Блокировка должна быть освобождена');
    }

    /**
     * Проверка освобождения с fallback на несколько бэкендов
     *
     * Сценарий: проверяем, что releaseOnAllBackends освобождает блокировки на всех
     * бэкендах, где они были захвачены, даже если некоторые бэкенды выбрасывают исключения.
     */
    public function testReleaseOnAllBackendsWithPartialFailure(): void
    {
        $b1 = new FailingMutex();
        $b2 = new MockMutex();
        $b3 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 1],
                ['mutex' => $b2, 'retries' => 1],
                ['mutex' => $b3, 'retries' => 1],
            ],
        ]);

        // Захватываем блокировки вручную на втором и третьем бэкенде
        $b2->acquire('test_lock', 0);
        $b3->acquire('test_lock', 0);

        // Используем рефлексию для сброса информации о бэкендах
        $reflection = new ReflectionClass($mutex);
        $property = $reflection->getProperty('acquiredLocks');
        $property->setValue($mutex, []);

        // Попытка освободить: первый бэкенд выбросит исключение, остальные освободят
        $result = $mutex->release('test_lock');

        self::assertTrue($result, 'Должна вернуть true если хотя бы один бэкенд освободил');
        self::assertFalse($b2->isLocked('test_lock'));
        self::assertFalse($b3->isLocked('test_lock'));
    }

    /**
     * Проверка обработки исключений при освобождении блокировки
     *
     * Сценарий: если при освобождении блокировки бэкенд выбрасывает исключение,
     * метод должен записать ошибку в breaker, удалить блокировку из отслеживания
     * и вернуть false.
     */
    public function testReleaseFailsWithException(): void
    {
        $b1 = new FailingMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retries' => 1],
            ],
        ]);

        // Используем рефлексию для имитации захваченной блокировки
        $reflection = new ReflectionClass($mutex);
        $property = $reflection->getProperty('acquiredLocks');
        $property->setValue($mutex, ['test_lock' => 0]);

        // Попытка освободить - должна обработать исключение
        $result = $mutex->release('test_lock');

        self::assertFalse($result, 'Должна вернуть false при ошибке освобождения');
        self::assertEmpty($mutex->getAcquiredLocks(), 'Блокировка должна быть удалена из отслеживания');
    }

    /**
     * Проверка пропуска открытых circuit breakers в глобальной стратегии повторов
     *
     * Сценарий: при использовании стратегии RETRY_GLOBAL, если circuit breaker одного
     * из бэкендов открыт, система должна пропустить его и попробовать следующий бэкенд
     * без увеличения счётчика глобальных попыток.
     */
    public function testGlobalRetrySkipsOpenCircuit(): void
    {
        $b1 = new MockMutex();
        $b2 = new MockMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retryDelay' => 1],
                ['mutex' => $b2, 'retryDelay' => 1],
            ],
            'retryStrategy' => ResilientMutex::RETRY_GLOBAL,
            'globalRetries' => 5,
        ]);

        // Принудительно открываем первый circuit breaker
        $mutex->forceBackendOpen(0);

        // Попытка захвата должна пропустить первый бэкенд и использовать второй
        $result = $mutex->acquire('test_lock', 5);

        self::assertTrue($result, 'Должна успешно захватить на втором бэкенде');
        self::assertFalse($b1->isLocked('test_lock'), 'Первый бэкенд не должен использоваться');
        self::assertTrue($b2->isLocked('test_lock'), 'Второй бэкенд должен захватить блокировку');
    }

    /**
     * Проверка превышения лимита глобальных повторов
     *
     * Сценарий: при использовании стратегии RETRY_GLOBAL с ограниченным количеством
     * попыток, если все попытки исчерпаны, система должна прекратить повторы и вернуть false.
     */
    public function testGlobalRetryExceedsLimit(): void
    {
        $b1 = new CountingMutex(100); // Будет отказывать 100 раз
        $b2 = new CountingMutex(100);

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retryDelay' => 1],
                ['mutex' => $b2, 'retryDelay' => 1],
            ],
            'retryStrategy' => ResilientMutex::RETRY_GLOBAL,
            'globalRetries' => 3, // Всего 3 попытки
        ]);

        $result = $mutex->acquire('test_lock', 0);

        self::assertFalse($result, 'Должна вернуть false после исчерпания попыток');
        // Проверяем что было сделано ровно 3 попытки (может быть меньше из-за распределения)
        self::assertLessThanOrEqual(3, $b1->attempts + $b2->attempts);
    }

    /**
     * Проверка неуспешного захвата после всех глобальных попыток
     *
     * Сценарий: если все бэкенды закрыты (circuit breakers в порядке), но блокировка
     * не может быть захвачена (заблокирована другим процессом) после всех попыток,
     * система должна вернуть false и залогировать ошибку.
     */
    public function testGlobalRetryFailsAfterAllAttempts(): void
    {
        // Создаём мьютексы, которые возвращают false (блокировка уже захвачена)
        $b1 = new AlreadyLockedMutex();
        $b2 = new AlreadyLockedMutex();

        $mutex = new ResilientMutex([
            'backends' => [
                ['mutex' => $b1, 'retryDelay' => 1],
                ['mutex' => $b2, 'retryDelay' => 1],
            ],
            'retryStrategy' => ResilientMutex::RETRY_GLOBAL,
            'globalRetries' => 4,
        ]);

        $result = $mutex->acquire('test_lock', 0);

        self::assertFalse($result, 'Должна вернуть false после всех неудачных попыток');
    }
}

/**
 * Тестовая заглушка mutex для проверки базового функционала
 */
class MockMutex extends Mutex
{
    private array $locks = [];

    /**
     * @param $name
     * @param $timeout
     *
     * @return bool
     */
    protected function acquireLock($name, $timeout = 0): bool
    {
        if (isset($this->locks[$name])) {
            return false; // Уже заблокировано
        }

        $this->locks[$name] = true;
        return true;
    }

    /**
     * @param $name
     *
     * @return bool
     */
    protected function releaseLock($name): bool
    {
        if (isset($this->locks[$name])) {
            unset($this->locks[$name]);
            return true;
        }

        return false;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function isLocked(string $name): bool
    {
        return isset($this->locks[$name]);
    }
}

/**
 * Тестовая заглушка mutex, имитирующая постоянные сбои
 */
class FailingMutex extends Mutex
{
    /**
     * @param $name
     * @param $timeout
     *
     * @return bool
     * @throws Exception
     */
    protected function acquireLock($name, $timeout = 0): bool
    {
        throw new RuntimeException('Mutex operation failed');
    }

    /**
     * @param $name
     *
     * @return bool
     * @throws Exception
     */
    protected function releaseLock($name): bool
    {
        throw new RuntimeException('Mutex operation failed');
    }
}

/**
 * Тестовая заглушка mutex для подсчёта попыток захвата блокировок
 */
class CountingMutex extends Mutex
{
    private int $failTimes;
    public int $attempts = 0;
    private array $locks = [];

    /**
     * @param int $failTimes Количество неудачных попыток перед успехом
     */
    public function __construct(int $failTimes = 0)
    {
        $this->failTimes = $failTimes;
        parent::__construct();
    }

    /**
     * @param $name
     * @param $timeout
     *
     * @return bool
     */
    protected function acquireLock($name, $timeout = 0): bool
    {
        $this->attempts++;

        if ($this->attempts <= $this->failTimes) {
            return false; // Отказываем первые N раз
        }

        $this->locks[$name] = true;
        return true;
    }

    /**
     * @param $name
     *
     * @return bool
     */
    protected function releaseLock($name): bool
    {
        if (isset($this->locks[$name])) {
            unset($this->locks[$name]);
            return true;
        }

        return false;
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public function isLocked(string $name): bool
    {
        return isset($this->locks[$name]);
    }
}

/**
 * Тестовая заглушка mutex, имитирующая уже захваченную блокировку (возвращает false)
 *
 * Используется для тестирования сценариев, когда блокировка уже захвачена другим
 * процессом и не может быть получена, но не выбрасывает исключений.
 */
class AlreadyLockedMutex extends Mutex
{
    /**
     * @param $name
     * @param $timeout
     *
     * @return bool Всегда возвращает false (блокировка уже захвачена)
     */
    protected function acquireLock($name, $timeout = 0): bool
    {
        return false; // Блокировка уже захвачена другим процессом
    }

    /**
     * @param $name
     *
     * @return bool Всегда возвращает false (блокировка не была захвачена)
     */
    protected function releaseLock($name): bool
    {
        return false; // Нечего освобождать
    }
}
