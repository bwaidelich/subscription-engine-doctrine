<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;

/**
 * The status fence of reset() and the transition of setup() with one connection per simulated process
 *
 * Requires the environment variable TEST_DATABASE_DSN, for example: pdo-mysql://root:password@127.0.0.1:3306/test
 */
#[CoversClass(DoctrineSubscriptionStore::class)]
final class ResetFenceTest extends TestCase
{
    /**
     * @var array<DoctrineSubscriptionStore>
     */
    private array $openStores = [];

    private Connection $connection;

    private DoctrineSubscriptionStore $store;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DATABASE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DATABASE_DSN is not set');
        }
        $this->connection = self::connect($dsn);
        foreach (['subscriptions', 'events', 'projection'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $autoIncrement = $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'BIGSERIAL' : 'BIGINT AUTO_INCREMENT';
        $this->connection->executeStatement('CREATE TABLE events (position ' . $autoIncrement . ' PRIMARY KEY, payload VARCHAR(10) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE projection (position BIGINT NOT NULL)');
        $this->store = new DoctrineSubscriptionStore($this->connection, 'subscriptions');
        $this->store->setup();
    }

    protected function tearDown(): void
    {
        foreach ($this->openStores as $store) {
            try {
                $store->rollback();
            } catch (Throwable) {
            }
        }
    }

    #[Test]
    public function aFenceWaitsForARunningCatchUpAndChangesNothingIfItTimesOut(): void
    {
        $this->addSubscription('s1', SubscriptionStatus::ACTIVE, 3);
        $this->addSubscription('s2', SubscriptionStatus::ACTIVE, 4);
        $worker = $this->openStore();
        $worker->findByCriteriaForUpdate(SubscriptionCriteria::create(['s2']));
        $this->setLockTimeoutOfOneSecond();
        $handlerCalled = false;
        $engine = $this->engine(Subscriber::create('s1', static fn() => null, reset: static function () use (&$handlerCalled) {
            $handlerCalled = true;
        }), Subscriber::create('s2', static fn() => null));

        try {
            $engine->reset();
            self::fail('Expected the lock timeout to escape');
        } catch (DbalException) {
        }

        self::assertFalse($handlerCalled);
        self::assertFalse($this->connection->isTransactionActive());
        $worker->rollback();
        self::assertSame('ACTIVE', $this->subscription('s1')->status->value);
        self::assertSame(3, $this->subscription('s1')->position->value);
        self::assertSame('ACTIVE', $this->subscription('s2')->status->value);
    }

    #[Test]
    public function workersFindNothingToDoWhileAResetHandlerRunsWithoutWaitingForLocks(): void
    {
        $this->addSubscription('s1', SubscriptionStatus::ACTIVE, 3);
        $criteriaOfWorkers = SubscriptionCriteria::create(null, [SubscriptionStatus::ACTIVE, SubscriptionStatus::BOOTING]);
        $claimed = 'not called';
        $locked = null;
        $engine = $this->engine(Subscriber::create('s1', static fn() => null, reset: function () use ($criteriaOfWorkers, &$claimed, &$locked) {
            $worker = $this->openStore();
            $claimed = $worker->claimNext($criteriaOfWorkers);
            $locked = $worker->findByCriteriaForUpdate($criteriaOfWorkers);
            $worker->rollback();
        }));

        self::assertTrue($engine->reset()->successful());

        self::assertNull($claimed);
        self::assertNotNull($locked);
        self::assertTrue($locked->isEmpty());
        self::assertSame('BOOTING', $this->subscription('s1')->status->value);
        self::assertSame(0, $this->subscription('s1')->position->value);
    }

    #[Test]
    public function aResetHandlerCanCommitImplicitly(): void
    {
        $this->addSubscription('s1', SubscriptionStatus::ACTIVE, 3);
        $this->connection->insert('projection', ['position' => 1]);
        $engine = $this->engine(Subscriber::create('s1', static fn() => null, reset: function () {
            $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? 'TRUNCATE projection' : 'TRUNCATE TABLE projection');
        }));

        self::assertTrue($engine->reset()->successful());

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM projection'));
        self::assertSame('BOOTING', $this->subscription('s1')->status->value);
    }

    #[Test]
    public function aFailingResetHandlerLeavesTheSubscriptionInError(): void
    {
        $this->addSubscription('s1', SubscriptionStatus::ACTIVE, 3);
        $engine = $this->engine(Subscriber::create('s1', static fn() => null, reset: static fn() => throw new \RuntimeException('failed')));

        self::assertFalse($engine->reset()->successful());

        $subscription = $this->subscription('s1');
        self::assertSame('ERROR', $subscription->status->value);
        self::assertSame(3, $subscription->position->value);
        self::assertSame('ACTIVE', $subscription->error?->previousStatus->value);
    }

    #[Test]
    public function aSecondResetIsRefusedUntilItIsForced(): void
    {
        $this->addSubscription('s1', SubscriptionStatus::RESETTING, 3);
        $handlerCalls = 0;
        $engine = $this->engine(Subscriber::create('s1', static fn() => null, reset: static function () use (&$handlerCalls) {
            $handlerCalls++;
        }));

        self::assertFalse($engine->reset()->successful());
        self::assertSame(0, $handlerCalls);
        self::assertSame('RESETTING', $this->subscription('s1')->status->value);

        self::assertTrue($engine->reset(force: true)->successful());
        self::assertSame(1, $handlerCalls);
        self::assertSame('BOOTING', $this->subscription('s1')->status->value);
        self::assertSame(0, $this->subscription('s1')->position->value);
    }

    #[Test]
    public function setupDoesNotRegressThePositionThatAWorkerCommittedDuringItsHandler(): void
    {
        $this->connection->executeStatement("INSERT INTO events (payload) VALUES ('{}'), ('{}'), ('{}')");
        $this->addSubscription('s1', SubscriptionStatus::BOOTING, 0);
        $engine = $this->engine(Subscriber::create('s1', static fn() => null, setup: function () {
            $worker = $this->openStore();
            $worker->update($this->subscription('s1')->with(position: Position::fromInteger(2)));
            $worker->commit();
        }));

        self::assertTrue($engine->setup()->successful());

        self::assertSame('BOOTING', $this->subscription('s1')->status->value);
        self::assertSame(2, $this->subscription('s1')->position->value);
    }

    #[Test]
    public function setupDoesNotWaitForARunningCatchUpBeforeItsHandler(): void
    {
        $this->addSubscription('s1', SubscriptionStatus::ACTIVE, 3);
        $this->addSubscription('s2', SubscriptionStatus::ACTIVE, 3);
        $worker = $this->openStore();
        $worker->findByCriteriaForUpdate(SubscriptionCriteria::create(['s2']));
        $this->setLockTimeoutOfOneSecond();
        $handlerCalled = false;
        $engine = $this->engine(
            Subscriber::create('s1', static fn() => null, setup: static function () use (&$handlerCalled) {
                $handlerCalled = true;
            }),
            Subscriber::create('s2', static fn() => null),
        );

        // s2 has no setup handler, but waits for the lock of its transition: Only s1 must be through by then
        try {
            $engine->setup();
        } catch (DbalException) {
        }

        self::assertTrue($handlerCalled);
    }

    // ------------------------------

    private function engine(Subscriber ...$subscribers): SubscriptionEngine
    {
        return new SubscriptionEngine(new DbalEventStoreAdapter($this->connection), $this->store, Subscribers::fromArray($subscribers));
    }

    private function subscription(string $id): Subscription
    {
        return $this->store->findByCriteria(SubscriptionCriteria::create([$id]))->get(SubscriptionId::fromString($id));
    }

    private function addSubscription(string $id, SubscriptionStatus $status, int $position): void
    {
        $this->store->add(Subscription::create($id, RunMode::FROM_BEGINNING, $status)->with(position: Position::fromInteger($position)));
    }

    private function openStore(): DoctrineSubscriptionStore
    {
        $store = new DoctrineSubscriptionStore(self::connect((string) getenv('TEST_DATABASE_DSN')), 'subscriptions');
        $store->beginTransaction();
        $this->openStores[] = $store;
        return $store;
    }

    private function setLockTimeoutOfOneSecond(): void
    {
        $this->connection->executeStatement($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");
    }

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
}
