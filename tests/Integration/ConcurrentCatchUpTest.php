<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;

/**
 * Worker processes with their own connections compete for the same subscriptions on a real database.
 * Handlers write to a table in the transaction of the catch-up with a unique key per subscription and position, so events that are lost or handled twice show up in the data.
 *
 * Requires the environment variable TEST_DATABASE_DSN, for example: pdo-mysql://root:password@127.0.0.1:3306/test
 */
#[CoversClass(DoctrineSubscriptionStore::class)]
final class ConcurrentCatchUpTest extends TestCase
{
    private const array ALL_SUBSCRIPTIONS = ['s1', 's2', 's3'];

    private Connection $connection;

    private string $runDirectory;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DATABASE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DATABASE_DSN is not set');
        }
        $this->connection = self::connect($dsn);
        foreach (['events', 'subscriptions', 'projection', 'contention', 'filler'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $autoIncrement = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'BIGSERIAL' : 'BIGINT AUTO_INCREMENT';
        $this->connection->executeStatement('CREATE TABLE events (position ' . $autoIncrement . ' PRIMARY KEY, payload VARCHAR(10) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE projection (subscription VARCHAR(50) NOT NULL, position BIGINT NOT NULL, worker VARCHAR(50) NOT NULL, PRIMARY KEY(subscription, position))');
        $this->runDirectory = sys_get_temp_dir() . '/' . uniqid('subscription-engine-doctrine-', true);
        mkdir($this->runDirectory);
    }

    protected function tearDown(): void
    {
        if (isset($this->runDirectory)) {
            array_map(unlink(...), glob($this->runDirectory . '/*') ?: []);
            rmdir($this->runDirectory);
        }
    }

    #[Test]
    public function workersTakeTurnsAndHandleEachEventExactlyOnce(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 400);

        $logs = $this->runWorkers(array_map(static fn(int $i) => ['name' => 'w' . $i, 'mode' => 'active', 'limit' => 40, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 200], [1, 2, 3, 4]));

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 400);
        self::assertSame(400, array_sum(array_column($logs, 'processed')));
        self::assertLessThanOrEqual(40, max(array_column($logs, 'processed')));
        $batches = self::batchesInOrder($logs);
        $workers = array_column($batches, 'worker');
        self::assertCount(4, array_unique($workers), 'All workers should have processed events');
        $switches = 0;
        for ($i = 1, $n = count($workers); $i < $n; $i++) {
            $switches += $workers[$i] !== $workers[$i - 1] ? 1 : 0;
        }
        self::assertGreaterThanOrEqual(5, $switches, 'Workers should take turns');
    }

    #[Test]
    public function limitShortensTheTimeOtherWorkersWaitForTheLock(): void
    {
        $maxWait = [];
        foreach (['unbounded' => null, 'bounded' => 20] as $label => $limit) {
            $this->resetData();
            $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 400);
            $worker = ['mode' => 'active', 'limit' => $limit, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 500];
            $logs = $this->runWorkers([['name' => 'w1'] + $worker, ['name' => 'w2', 'delayMs' => 200] + $worker]);
            $this->assertNoFailures($logs);
            $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 400);
            $maxWait[$label] = max(array_map(static fn(array $run) => $run['started'] - $run['initiated'], array_filter($logs, static fn(array $run) => isset($run['started']))));
        }
        self::assertLessThan($maxWait['unbounded'] / 3, $maxWait['bounded'], json_encode($maxWait, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function concurrentBootsWithWritesActivateOnceAndKeepActiveCatchUpsOut(): void
    {
        $this->prepareBootingSubscriptions(self::ALL_SUBSCRIPTIONS, 300);
        $boot = ['mode' => 'boot', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 200];
        $observer = self::connect((string) getenv('TEST_DATABASE_DSN'));
        $samples = [];

        $logs = $this->runWorkers([
            ['name' => 'b1'] + $boot,
            ['name' => 'b2'] + $boot,
            ['name' => 'b3'] + $boot,
            ['name' => 'a1', 'mode' => 'active', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'useStop' => true],
            ['name' => 'writer', 'mode' => 'writer', 'count' => 60, 'sleepUs' => 5000, 'delayMs' => 50],
        ], static function () use ($observer, &$samples): void {
            foreach ($observer->fetchAllAssociative('SELECT id, status, position FROM subscriptions') as $row) {
                $samples[$row['id']][] = [$row['status'], (int) $row['position']];
            }
        });

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 360);
        self::assertSame(3, array_sum(array_column($logs, 'activated')), 'SubscriptionActivated should fire once per subscription');
        $activatedAt = min(array_column(array_filter($logs, static fn(array $run) => isset($run['activated'])), 'started'));
        foreach ($logs as $run) {
            if ($run['worker'] === 'a1' && ($run['processed'] ?? 0) > 0) {
                self::assertGreaterThanOrEqual($activatedAt, $run['started'], 'catchUpActive should not process partially booted subscriptions');
            }
        }
        foreach ($samples as $id => $states) {
            $previous = ['BOOTING', 0];
            foreach ($states as $state) {
                self::assertGreaterThanOrEqual($previous[1], $state[1], "Position of $id should never decrease");
                self::assertFalse($previous[0] === 'ACTIVE' && $state[0] !== 'ACTIVE', "$id should stay ACTIVE");
                $previous = $state;
            }
        }
    }

    #[Test]
    public function concurrentBootsDoNotDeadlock(): void
    {
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->resetData();
            $this->prepareBootingSubscriptions(self::ALL_SUBSCRIPTIONS, 100);
            $boot = ['mode' => 'boot', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS];
            $logs = $this->runWorkers([['name' => 'b1'] + $boot, ['name' => 'b2'] + $boot, ['name' => 'b3'] + $boot]);
            $this->assertNoFailures($logs);
            $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 100);
        }
    }

    #[Test]
    public function aWorkerKilledMidBatchLeavesNoTrace(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 400);
        $worker = ['mode' => 'active', 'limit' => 40, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 200];

        $logs = $this->runWorkers([['name' => 'w1', 'killAtHandlerCall' => 170] + $worker, ['name' => 'w2'] + $worker, ['name' => 'w3'] + $worker], allowedToBeKilled: 'w1');

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 400);
    }

    #[Test]
    public function runsForDifferentSubscriptionsExecuteInParallel(): void
    {
        $subscriptions = ['s1', 's2'];
        $this->prepareActiveSubscriptions($subscriptions, 300);
        $worker = ['mode' => 'active', 'limit' => 25, 'subscriptions' => $subscriptions, 'sleepUs' => 500];

        $logs = $this->runWorkers([['name' => 'w1', 'ids' => ['s1']] + $worker, ['name' => 'w2', 'ids' => ['s2']] + $worker]);

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce($subscriptions, 300);
        self::assertGreaterThan(0.5, self::shareOfW1OverlappingW2($logs));
    }

    #[Test]
    public function claimingWorkersHandleEachEventExactlyOnce(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 400);
        $worker = ['mode' => 'activeNext', 'limit' => 40, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 200];

        $logs = $this->runWorkers([['name' => 'w1'] + $worker, ['name' => 'w2'] + $worker, ['name' => 'w3'] + $worker, ['name' => 'w4'] + $worker]);

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 400);
        self::assertSame(1200, array_sum(array_column($logs, 'processed')));
        self::assertLessThanOrEqual(40, max(array_column($logs, 'processed')));
    }

    #[Test]
    public function claimingWorkersRunInParallelWithTheSameCriteria(): void
    {
        $subscriptions = ['s1', 's2'];
        $this->prepareActiveSubscriptions($subscriptions, 300);
        $worker = ['mode' => 'activeNext', 'limit' => 25, 'subscriptions' => $subscriptions, 'sleepUs' => 500];

        $logs = $this->runWorkers([['name' => 'w1'] + $worker, ['name' => 'w2'] + $worker]);

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce($subscriptions, 300);
        self::assertGreaterThan(0.5, self::shareOfW1OverlappingW2($logs));
    }

    #[Test]
    public function claimingWorkersBootAndActivateEachSubscriptionOnce(): void
    {
        $this->prepareBootingSubscriptions(self::ALL_SUBSCRIPTIONS, 300);
        $boot = ['mode' => 'bootNext', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 200];

        $logs = $this->runWorkers([
            ['name' => 'b1'] + $boot,
            ['name' => 'b2'] + $boot,
            ['name' => 'b3'] + $boot,
            ['name' => 'a1', 'mode' => 'activeNext', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'useStop' => true],
            ['name' => 'writer', 'mode' => 'writer', 'count' => 60, 'sleepUs' => 5000, 'delayMs' => 50],
        ]);

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 360);
        self::assertSame(3, array_sum(array_column($logs, 'activated')), 'SubscriptionActivated should fire once per subscription');
    }

    #[Test]
    public function aClaimingWorkerKilledMidBatchLeavesNoTrace(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 400);
        $worker = ['mode' => 'activeNext', 'limit' => 40, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 200];

        $logs = $this->runWorkers([['name' => 'w1', 'killAtHandlerCall' => 60] + $worker, ['name' => 'w2', 'useStop' => true] + $worker, ['name' => 'w3', 'useStop' => true] + $worker], allowedToBeKilled: 'w1');

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 400);
    }

    #[Test]
    public function claimingAndBlockingWorkersCanBeMixed(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 400);
        $worker = ['limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 100];

        $logs = $this->runWorkers([
            ['name' => 'c1', 'mode' => 'activeNext'] + $worker,
            ['name' => 'c2', 'mode' => 'activeNext'] + $worker,
            ['name' => 'b1', 'mode' => 'active'] + $worker,
            ['name' => 'b2', 'mode' => 'active'] + $worker,
        ]);

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 400);
    }

    #[Test]
    public function aSubscriptionWithARepeatingSqlErrorDoesNotStarveTheOthersOfASingleWorker(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 60);

        $logs = $this->runWorkers([
            ['name' => 'w1', 'mode' => 'activeNext', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sqlErrorAt' => ['subscription' => 's1', 'position' => 10]],
        ]);

        self::assertSame([], array_values(array_filter(array_column($logs, 'fatal'))));
        self::assertSame([], array_column($logs, 'outOfOrder'));
        $this->assertEachEventHandledExactlyOnce(['s2', 's3'], 60);
        $this->assertPositionsInLineWithHandledEvents(['s1'], json_encode($logs, JSON_THROW_ON_ERROR));
        self::assertGreaterThan(0, array_sum(array_column($logs, 'errors')));
        if ($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::assertSame(['status' => 'ACTIVE', 'position' => 0, 'rows' => 0], $this->subscriptionState('s1'), 'An SQL error ends the transaction on PostgreSQL, so the subscription should stay retryable');
        }
    }

    #[Test]
    public function workersWithDifferentCriteriaDoNotDeadlock(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 300);
        $worker = ['mode' => 'active', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 100];

        $logs = $this->runWorkers([
            ['name' => 'w1'] + $worker,
            ['name' => 'w2'] + $worker,
            ['name' => 'w3', 'ids' => ['s1']] + $worker,
            ['name' => 'w4', 'ids' => ['s3']] + $worker,
            ['name' => 'w5', 'ids' => ['s3', 's1']] + $worker,
        ]);

        $this->assertNoFailures($logs);
        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 300);
    }

    #[Test]
    public function aFailingSubscriberStopsAtItsLastPositionWithoutAffectingOthers(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 400);
        $worker = ['mode' => 'active', 'limit' => 40, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 100, 'poison' => ['subscription' => 's2', 'position' => 177]];

        $logs = $this->runWorkers([['name' => 'w1'] + $worker, ['name' => 'w2'] + $worker, ['name' => 'w3'] + $worker]);

        $this->assertNoFailures($logs, expectedHandlerErrors: 1);
        $this->assertEachEventHandledExactlyOnce(['s1', 's3'], 400);
        self::assertSame(['status' => 'ERROR', 'position' => 176, 'rows' => 176], $this->subscriptionState('s2'));
    }

    #[Test]
    public function workersThatKeepGoingAfterAnEscapingExceptionPersistEverything(): void
    {
        $this->prepareBootingSubscriptions(self::ALL_SUBSCRIPTIONS, 200);
        $worker = ['mode' => 'keepGoing', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 100, 'useStop' => true, 'failAfterHandledEvent' => 2];

        $logs = $this->runWorkers([
            ['name' => 'k1'] + $worker,
            ['name' => 'k2'] + $worker,
            ['name' => 'writer', 'mode' => 'writer', 'count' => 150, 'sleepUs' => 10000],
        ]);

        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 350);
        $caught = array_sum(array_map(static fn(array $run) => array_sum($run['caught']), $logs));
        self::assertSame(4, $caught, 'Each worker should have caught its two exceptions');
    }

    #[Test]
    public function workersThatKeepGoingAfterARealDeadlockPersistEverything(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Deadlocks of concurrent boots only occur on MySQL and MariaDB');
        }
        $this->prepareBootingSubscriptions(self::ALL_SUBSCRIPTIONS, 200);
        $worker = ['mode' => 'keepGoing', 'store' => 'repeatableRead', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 100, 'useStop' => true];

        $logs = $this->runWorkers([
            ['name' => 'k1'] + $worker,
            ['name' => 'k2'] + $worker,
            ['name' => 'k3'] + $worker,
            ['name' => 'writer', 'mode' => 'writer', 'count' => 150, 'sleepUs' => 10000],
        ]);

        $this->assertEachEventHandledExactlyOnce(self::ALL_SUBSCRIPTIONS, 350);
        $deadlocks = array_sum(array_map(static fn(array $run) => $run['caught'][DeadlockException::class] ?? 0, $logs));
        self::assertGreaterThan(0, $deadlocks, 'This test requires a real deadlock: ' . json_encode($logs, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function negativeControlDetectsDoubleHandlingWithoutRowLocks(): void
    {
        $this->prepareActiveSubscriptions(self::ALL_SUBSCRIPTIONS, 200);
        $worker = ['mode' => 'active', 'store' => 'withoutRowLocks', 'limit' => 25, 'subscriptions' => self::ALL_SUBSCRIPTIONS, 'sleepUs' => 200];

        $logs = $this->runWorkers([['name' => 'w1'] + $worker, ['name' => 'w2'] + $worker, ['name' => 'w3'] + $worker]);

        $problems = array_sum(array_column($logs, 'errors')) + count(array_filter(array_column($logs, 'fatal')));
        self::assertGreaterThan(0, $problems, 'Without row locks, workers should have collided');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function subscriptionsWithADeadlockInTheirHandler(): iterable
    {
        yield 'first subscriber of an event' => ['s1'];
        yield 'second subscriber of an event' => ['s2'];
    }

    #[Test]
    #[DataProvider('subscriptionsWithADeadlockInTheirHandler')]
    public function aDeadlockInAHandlerKeepsPositionsInLineWithTheHandledEvents(string $subscription): void
    {
        $this->prepareHandlerDeadlock();
        $signals = $this->handlerDeadlockSignals();

        $logs = $this->runWorkers([
            ['name' => 'rival', 'mode' => 'deadlockRival'] + $signals,
            ['name' => 'w1', 'mode' => 'active', 'limit' => 25, 'subscriptions' => ['s1', 's2'], 'deadlockAt' => ['subscription' => $subscription, 'position' => 10]] + $signals,
        ]);

        $context = json_encode($logs, JSON_THROW_ON_ERROR);
        self::assertContains(DeadlockException::class, array_column($logs, 'handlerFailed'), 'This test requires the handler to be the deadlock victim: ' . $context);
        $this->assertPositionsInLineWithHandledEvents(['s1', 's2'], $context);
        self::assertSame(['status' => 'ACTIVE', 'position' => 0, 'rows' => 0], $this->subscriptionState($subscription), 'A deadlock is temporary, the subscription should not go to ERROR: ' . $context);
    }

    #[Test]
    public function aWorkerThatKeepsGoingAfterADeadlockInAHandlerHandlesEachEventExactlyOnce(): void
    {
        $this->prepareHandlerDeadlock();
        $signals = $this->handlerDeadlockSignals();

        $logs = $this->runWorkers([
            ['name' => 'rival', 'mode' => 'deadlockRival'] + $signals,
            ['name' => 'k1', 'mode' => 'keepGoing', 'limit' => 25, 'subscriptions' => ['s1', 's2'], 'useStop' => true, 'deadlockAt' => ['subscription' => 's1', 'position' => 10]] + $signals,
            ['name' => 'writer', 'mode' => 'writer', 'count' => 100, 'sleepUs' => 10000],
        ]);

        $context = json_encode($logs, JSON_THROW_ON_ERROR);
        self::assertContains(DeadlockException::class, array_column($logs, 'handlerFailed'), 'This test requires the handler to be the deadlock victim: ' . $context);
        $this->assertEachEventHandledExactlyOnce(['s1', 's2'], 160);
    }

    #[Test]
    public function anSqlErrorInAHandlerKeepsPositionsInLineWithTheHandledEvents(): void
    {
        $this->prepareActiveSubscriptions(['s1', 's2'], 60);

        $logs = $this->runWorkers([
            ['name' => 'w1', 'mode' => 'active', 'limit' => 25, 'subscriptions' => ['s1', 's2'], 'sqlErrorAt' => ['subscription' => 's1', 'position' => 10]],
        ]);

        $this->assertPositionsInLineWithHandledEvents(['s1', 's2'], json_encode($logs, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function transactionsCanBeNested(): void
    {
        $store = new DoctrineSubscriptionStore($this->connection, 'subscriptions');
        $store->setup();

        $store->beginTransaction();
        $store->beginTransaction();
        $store->commit();
        $store->commit();

        $this->connection->beginTransaction();
        $store->beginTransaction();
        $store->commit();
        $this->connection->commit();

        self::assertFalse($this->connection->isTransactionActive());
    }

    #[Test]
    public function isolationLevelDoesNotLeakIntoTheSession(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('The isolation level is only changed on MySQL and MariaDB');
        }
        $store = new DoctrineSubscriptionStore($this->connection, 'subscriptions');
        $store->setup();
        $before = $this->connection->fetchOne('SELECT @@SESSION.transaction_isolation');

        $store->beginTransaction();
        $store->commit();

        self::assertSame($before, $this->connection->fetchOne('SELECT @@SESSION.transaction_isolation'));
    }

    // ------------------------------

    private static function connect(string $dsn): Connection
    {
        $configuration = new Configuration();
        $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());
        $connection = DriverManager::getConnection((new DsnParser())->parse($dsn), $configuration);
        for ($attempt = 1; ; $attempt++) {
            try {
                $connection->executeQuery('SELECT 1');
                return $connection;
            } catch (Throwable $e) {
                if ($attempt === 90) {
                    throw $e;
                }
                sleep(1);
            }
        }
    }

    private function resetData(): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS subscriptions');
        $this->connection->executeStatement('DELETE FROM projection');
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'TRUNCATE events RESTART IDENTITY' : 'TRUNCATE TABLE events');
    }

    /**
     * @param array<string> $subscriptions
     */
    private function engine(array $subscriptions): SubscriptionEngine
    {
        return new SubscriptionEngine(
            new DbalEventStoreAdapter($this->connection),
            new DoctrineSubscriptionStore($this->connection, 'subscriptions'),
            Subscribers::fromArray(array_map(static fn(string $id) => Subscriber::create($id, static fn() => null), $subscriptions)),
        );
    }

    /**
     * @param array<string> $subscriptions
     */
    private function prepareActiveSubscriptions(array $subscriptions, int $backlog): void
    {
        $engine = $this->engine($subscriptions);
        $engine->setup();
        $engine->boot();
        (new DbalEventStoreAdapter($this->connection))->append($backlog);
    }

    /**
     * @param array<string> $subscriptions
     */
    private function prepareBootingSubscriptions(array $subscriptions, int $backlog): void
    {
        (new DbalEventStoreAdapter($this->connection))->append($backlog);
        $this->engine($subscriptions)->setup();
    }

    /**
     * @param array<array<string, mixed>> $workers
     * @return array<array<string, mixed>>
     */
    private function runWorkers(array $workers, callable|null $whileRunning = null, string|null $allowedToBeKilled = null): array
    {
        $go = $this->runDirectory . '/go';
        $stop = $this->runDirectory . '/stop';
        $log = $this->runDirectory . '/log.jsonl';
        @unlink($go);
        @unlink($stop);
        file_put_contents($log, '');
        $processes = [];
        foreach ($workers as $worker) {
            $configuration = $worker + ['go' => $go, 'log' => $log];
            if (!empty($worker['useStop'])) {
                $configuration['stop'] = $stop;
            }
            $output = $this->runDirectory . '/' . $worker['name'] . '.out';
            $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php', json_encode($configuration, JSON_THROW_ON_ERROR)], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']], $pipes);
            self::assertIsResource($process);
            $processes[$worker['name']] = ['process' => $process, 'output' => $output, 'useStop' => !empty($worker['useStop'])];
        }
        usleep(300000);
        touch($go);
        $startTime = microtime(true);
        $exits = [];
        while (count($exits) < count($processes)) {
            foreach ($processes as $name => $process) {
                if (isset($exits[$name])) {
                    continue;
                }
                $status = proc_get_status($process['process']);
                if (!$status['running']) {
                    $exits[$name] = $status['signaled'] ? 'signal ' . $status['termsig'] : 'exit ' . $status['exitcode'];
                }
            }
            if ($whileRunning !== null) {
                $whileRunning();
            }
            $othersFinished = array_filter($processes, static fn(array $process, string $name) => !$process['useStop'] && !isset($exits[$name]), ARRAY_FILTER_USE_BOTH) === [];
            if ($othersFinished && !file_exists($stop)) {
                touch($stop);
            }
            if (microtime(true) - $startTime > 120) {
                foreach ($processes as $name => $process) {
                    if (!isset($exits[$name])) {
                        proc_terminate($process['process'], 9);
                        $exits[$name] = 'hung';
                    }
                }
            }
            usleep(10000);
        }
        foreach ($exits as $name => $exit) {
            $expected = $name === $allowedToBeKilled ? 'signal 9' : 'exit 0';
            $isFatal = $exit === 'exit 3';
            self::assertTrue($exit === $expected || $isFatal, sprintf('Worker %s: %s, output: %s', $name, $exit, (string) file_get_contents($processes[$name]['output'])));
        }
        return array_map(static fn(string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($log) ?: []);
    }

    /**
     * @param array<array<string, mixed>> $logs
     */
    private function assertNoFailures(array $logs, int $expectedHandlerErrors = 0): void
    {
        self::assertSame([], array_values(array_filter(array_column($logs, 'fatal'))));
        self::assertSame([], array_column($logs, 'outOfOrder'));
        self::assertSame($expectedHandlerErrors, array_sum(array_column($logs, 'errors')));
    }

    /**
     * @param array<string> $subscriptions
     */
    private function assertEachEventHandledExactlyOnce(array $subscriptions, int $numberOfEvents): void
    {
        foreach ($subscriptions as $id) {
            self::assertSame(['status' => 'ACTIVE', 'position' => $numberOfEvents, 'rows' => $numberOfEvents], $this->subscriptionState($id), "Subscription $id");
        }
    }

    private function prepareHandlerDeadlock(): void
    {
        if (!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('InnoDB rolls back the whole transaction of a deadlock victim, PostgreSQL keeps it open until the rollback');
        }
        $this->connection->executeStatement('CREATE TABLE contention (id INT PRIMARY KEY, value INT NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE filler (value INT NOT NULL)');
        $this->connection->executeStatement('INSERT INTO contention (id, value) VALUES (1, 0), (2, 0)');
        $this->prepareActiveSubscriptions(['s1', 's2'], 60);
    }

    /**
     * @return array{rivalHolds: string, handlerHolds: string}
     */
    private function handlerDeadlockSignals(): array
    {
        return ['rivalHolds' => $this->runDirectory . '/rival-holds', 'handlerHolds' => $this->runDirectory . '/handler-holds'];
    }

    /**
     * A position promises that all events up to it have been handled, no matter how a run ended
     *
     * @param array<string> $subscriptions
     */
    private function assertPositionsInLineWithHandledEvents(array $subscriptions, string $context): void
    {
        foreach ($subscriptions as $id) {
            $state = $this->subscriptionState($id);
            self::assertSame($state['position'], $state['rows'], "Subscription $id: " . json_encode($state, JSON_THROW_ON_ERROR) . ' ' . $context);
        }
    }

    /**
     * @return array{status: string, position: int, rows: int}
     */
    private function subscriptionState(string $id): array
    {
        $subscription = $this->connection->fetchAssociative('SELECT status, position FROM subscriptions WHERE id = ?', [$id]);
        self::assertIsArray($subscription);
        return [
            'status' => (string) $subscription['status'],
            'position' => (int) $subscription['position'],
            'rows' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projection WHERE subscription = ?', [$id]),
        ];
    }

    /**
     * @param array<array<string, mixed>> $logs
     */
    private static function shareOfW1OverlappingW2(array $logs): float
    {
        $batches = self::batchesInOrder($logs);
        $overlap = 0.0;
        $total = 0.0;
        foreach ($batches as $a) {
            if ($a['worker'] !== 'w1') {
                continue;
            }
            $total += $a['finished'] - $a['started'];
            foreach ($batches as $b) {
                if ($b['worker'] === 'w2') {
                    $overlap += max(0, min($a['finished'], $b['finished']) - max($a['started'], $b['started']));
                }
            }
        }
        return $total > 0 ? $overlap / $total : 0.0;
    }

    /**
     * @param array<array<string, mixed>> $logs
     * @return array<array<string, mixed>>
     */
    private static function batchesInOrder(array $logs): array
    {
        $batches = array_values(array_filter($logs, static fn(array $run) => isset($run['started'], $run['finished']) && $run['processed'] > 0));
        usort($batches, static fn(array $a, array $b) => $a['started'] <=> $b['started']);
        return $batches;
    }
}
