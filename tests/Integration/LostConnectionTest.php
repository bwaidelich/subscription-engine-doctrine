<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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
 * DBAL closes a connection on its own if a statement reports that it is lost. The next statement reconnects and runs outside of the transaction, which is gone along with the changes in it.
 * The positions must not be written in that case, they would stand for events that were not persisted.
 *
 * Requires the environment variable TEST_DATABASE_DSN, for example: pdo-mysql://root:password@127.0.0.1:3306/test
 */
#[CoversClass(DoctrineSubscriptionStore::class)]
final class LostConnectionTest extends TestCase
{
    private Connection $connection;
    private Connection $observer;
    private DoctrineSubscriptionStore $store;
    private DbalEventStoreAdapter $eventStore;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DATABASE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DATABASE_DSN is not set');
        }
        $this->connection = self::connect($dsn);
        $this->observer = self::connect($dsn);
        foreach (['events', 'subscriptions', 'projection'] as $table) {
            $this->connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $autoIncrement = $this->isPostgreSql() ? 'BIGSERIAL' : 'BIGINT AUTO_INCREMENT';
        $this->connection->executeStatement('CREATE TABLE events (position ' . $autoIncrement . ' PRIMARY KEY, payload VARCHAR(10) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE projection (subscription VARCHAR(50) NOT NULL, position BIGINT NOT NULL, PRIMARY KEY(subscription, position))');
        $this->store = new DoctrineSubscriptionStore($this->connection, 'subscriptions');
        $this->store->setup();
        $this->eventStore = new DbalEventStoreAdapter($this->connection);
        $this->eventStore->append(5);
    }

    #[Test]
    public function aClaimedRunKeepsItsPositionAndStatusAndCanBeRepeated(): void
    {
        $this->addActiveSubscriptions('s1');
        $engine = $this->engine(['s1'], loseConnectionAt: 3);

        $result = $engine->catchUpNextActive();

        self::assertFalse($result->successful());
        $this->assertSubscription('s1', SubscriptionStatus::ACTIVE, 0);
        self::assertSame(0, $this->numberOfProjectedRows());

        $this->connection->close();
        $result = $engine->catchUpNextActive();

        self::assertTrue($result->successful());
        $this->assertSubscription('s1', SubscriptionStatus::ACTIVE, 5);
        self::assertSame(5, $this->numberOfProjectedRows());
    }

    #[Test]
    public function aBlockingRunDoesNotContinueOutsideOfTheTransaction(): void
    {
        $this->addActiveSubscriptions('s1', 's2');
        $engine = $this->engine(['s1', 's2'], loseConnectionAt: 3);

        $exception = null;
        try {
            $engine->catchUpActive();
        } catch (Throwable $e) {
            $exception = $e;
        }

        $this->assertSubscription('s1', SubscriptionStatus::ACTIVE, 0);
        $this->assertSubscription('s2', SubscriptionStatus::ACTIVE, 0);
        self::assertSame(0, $this->numberOfProjectedRows());
        self::assertInstanceOf(RuntimeException::class, $exception);
        self::assertSame(1793750402, $exception->getCode());
    }

    #[Test]
    public function writesFailIfTheConnectionWasClosedDuringATransaction(): void
    {
        $this->addActiveSubscriptions('s1');
        $subscription = $this->observerStore()->findByCriteria(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('s1'));

        $this->store->beginTransaction();
        $this->connection->close();

        try {
            $this->store->update($subscription->with(position: Position::fromInteger(99)));
            self::fail('update() should have failed');
        } catch (RuntimeException $e) {
            self::assertSame(1793750402, $e->getCode());
        }
        try {
            $this->store->add(Subscription::create('s2', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
            self::fail('add() should have failed');
        } catch (RuntimeException $e) {
            self::assertSame(1793750402, $e->getCode());
        }
        $this->assertSubscription('s1', SubscriptionStatus::ACTIVE, 0);
    }

    #[Test]
    public function theStoreIsUsableAgainAfterTheRollback(): void
    {
        $this->addActiveSubscriptions('s1');
        $subscription = $this->observerStore()->findByCriteria(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('s1'));
        $this->store->beginTransaction();
        $this->connection->close();
        try {
            $this->store->rollback();
        } catch (Throwable) {
            // the engine ignores this as well: There is nothing left to roll back
        }

        $this->store->update($subscription->with(position: Position::fromInteger(1)));
        $this->assertSubscription('s1', SubscriptionStatus::ACTIVE, 1);

        $this->store->beginTransaction();
        $this->store->update($subscription->with(position: Position::fromInteger(2)));
        $this->store->commit();
        $this->assertSubscription('s1', SubscriptionStatus::ACTIVE, 2);
    }

    #[Test]
    public function theOuterTransactionIsStillProtectedAfterANestedOneWasCommitted(): void
    {
        $this->addActiveSubscriptions('s1');
        $subscription = $this->observerStore()->findByCriteria(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('s1'));
        $this->store->beginTransaction();
        $this->store->beginTransaction();
        $this->store->commit();
        $this->connection->close();

        $this->expectExceptionCode(1793750402);
        $this->store->update($subscription->with(position: Position::fromInteger(99)));
    }

    /**
     * @param list<string> $subscriptionIds
     * @return SubscriptionEngine<TestEvent>
     */
    private function engine(array $subscriptionIds, int $loseConnectionAt): SubscriptionEngine
    {
        $lost = false;
        $subscribers = array_map(fn(string $id) => Subscriber::create($id, function (TestEvent $event) use ($id, $loseConnectionAt, &$lost): void {
            if (!$lost && $event->position->value === $loseConnectionAt) {
                $lost = true;
                $this->terminateConnection();
            }
            $this->connection->insert('projection', ['subscription' => $id, 'position' => $event->position->value]);
        }), $subscriptionIds);
        return new SubscriptionEngine($this->eventStore, $this->store, Subscribers::fromArray($subscribers));
    }

    private function terminateConnection(): void
    {
        if ($this->isPostgreSql()) {
            $id = (int) $this->connection->fetchOne('SELECT pg_backend_pid()');
            $this->observer->fetchOne('SELECT pg_terminate_backend(?)', [$id]);
            $query = 'SELECT COUNT(*) FROM pg_stat_activity WHERE pid = ?';
        } else {
            $id = (int) $this->connection->fetchOne('SELECT CONNECTION_ID()');
            $this->observer->executeStatement('KILL ' . $id);
            $query = 'SELECT COUNT(*) FROM information_schema.processlist WHERE id = ?';
        }
        $deadline = microtime(true) + 5.0;
        while ((int) $this->observer->fetchOne($query, [$id]) > 0) {
            if (microtime(true) > $deadline) {
                self::fail('The connection was not terminated');
            }
            usleep(1000);
        }
    }

    private function addActiveSubscriptions(string ...$ids): void
    {
        foreach ($ids as $id) {
            $this->store->add(Subscription::create($id, RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        }
    }

    private function assertSubscription(string $id, SubscriptionStatus $status, int $position): void
    {
        $row = $this->observer->fetchAssociative('SELECT status, position FROM subscriptions WHERE id = ?', [$id]);
        self::assertSame(['status' => $status->value, 'position' => $position], $row);
    }

    private function numberOfProjectedRows(): int
    {
        return (int) $this->observer->fetchOne('SELECT COUNT(*) FROM projection');
    }

    private function observerStore(): DoctrineSubscriptionStore
    {
        return new DoctrineSubscriptionStore($this->observer, 'subscriptions');
    }

    private function isPostgreSql(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform;
    }

    private static function connect(string $dsn): Connection
    {
        $configuration = new Configuration();
        $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());
        return DriverManager::getConnection((new DsnParser())->parse($dsn), $configuration);
    }
}
