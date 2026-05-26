<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests;

use DateTimeImmutable;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionError;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;

#[CoversClass(DoctrineSubscriptionStore::class)]
final class DoctrineSubscriptionStoreTest extends TestCase
{
    private const TABLE_NAME = 'subscriptions';

    private Connection $connection;

    private DateTimeImmutable $now;

    private DoctrineSubscriptionStore $store;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2025-01-15 10:20:30');
        $configuration = new Configuration();
        $configuration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
        $this->store = new DoctrineSubscriptionStore($this->connection, self::TABLE_NAME, $this->fixedClock($this->now));
    }

    #[Test]
    public function setupCreatesTheConfiguredTable(): void
    {
        $this->store->setup();

        $tables = $this->connection->createSchemaManager()->listTableNames();
        self::assertContains(self::TABLE_NAME, $tables);
    }

    #[Test]
    public function setupCanBeCalledRepeatedly(): void
    {
        $this->store->setup();
        $this->store->setup();

        $this->store->add(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        self::assertCount(1, $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints()));
    }

    #[Test]
    public function setupThrowsForInvalidConnection(): void
    {
        $this->connection->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionCode(1736174563);
        // forcing a failure by setting up against a non-existent table name that contains invalid characters
        (new DoctrineSubscriptionStore($this->connection, 'invalid"name', $this->fixedClock($this->now)))->setup();
    }

    #[Test]
    public function findByCriteriaForUpdateReturnsNoneWhenStoreIsEmpty(): void
    {
        $this->store->setup();

        self::assertSameSubscriptionIds([], $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints()));
    }

    #[Test]
    public function addPersistsAllFields(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('foo', RunMode::ONCE, SubscriptionStatus::BOOTING));

        $subscriptions = $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints());
        self::assertCount(1, $subscriptions);

        $subscription = $subscriptions->get(SubscriptionId::fromString('foo'));
        self::assertSame('foo', $subscription->id->value);
        self::assertSame(RunMode::ONCE, $subscription->runMode);
        self::assertSame(SubscriptionStatus::BOOTING, $subscription->status);
        self::assertSame(0, $subscription->position->value);
        self::assertNull($subscription->error);
        self::assertSame($this->now->format('Y-m-d H:i:s'), $subscription->lastSavedAt?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function addPersistsErrorDetails(): void
    {
        $this->store->setup();
        $error = new SubscriptionError('Something went wrong', SubscriptionStatus::ACTIVE, "trace line 1\ntrace line 2");
        $subscription = new Subscription(
            SubscriptionId::fromString('faulty'),
            RunMode::FROM_BEGINNING,
            SubscriptionStatus::ERROR,
            Position::fromInteger(42),
            $error,
            null,
        );
        $this->store->add($subscription);

        $loaded = $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('faulty'));
        self::assertSame(42, $loaded->position->value);
        self::assertNotNull($loaded->error);
        self::assertSame('Something went wrong', $loaded->error->errorMessage);
        self::assertSame(SubscriptionStatus::ACTIVE, $loaded->error->previousStatus);
        self::assertSame("trace line 1\ntrace line 2", $loaded->error->errorTrace);
    }

    #[Test]
    public function updateChangesAnExistingSubscription(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::BOOTING));

        $updated = (new Subscription(
            SubscriptionId::fromString('foo'),
            RunMode::FROM_BEGINNING,
            SubscriptionStatus::ACTIVE,
            Position::fromInteger(5),
            null,
            null,
        ));
        $this->store->update($updated);

        $loaded = $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('foo'));
        self::assertSame(SubscriptionStatus::ACTIVE, $loaded->status);
        self::assertSame(5, $loaded->position->value);
        self::assertCount(1, $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints()));
    }

    #[Test]
    public function updateCanClearAPreviouslyStoredError(): void
    {
        $this->store->setup();
        $this->store->add(new Subscription(
            SubscriptionId::fromString('foo'),
            RunMode::FROM_BEGINNING,
            SubscriptionStatus::ERROR,
            Position::none(),
            new SubscriptionError('boom', SubscriptionStatus::ACTIVE, 'trace'),
            null,
        ));

        $this->store->update(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        $loaded = $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints())->get(SubscriptionId::fromString('foo'));
        self::assertNull($loaded->error);
    }

    #[Test]
    public function findByCriteriaForUpdateReturnsSubscriptionsOrderedById(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('charlie', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('alpha', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('bravo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        self::assertSameSubscriptionIds(['alpha', 'bravo', 'charlie'], $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints()));
    }

    #[Test]
    public function findByCriteriaForUpdateFiltersByIds(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('bar', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('baz', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        $result = $this->store->findByCriteriaForUpdate(SubscriptionCriteria::create(ids: ['foo', 'baz']));
        self::assertSameSubscriptionIds(['baz', 'foo'], $result);
    }

    #[Test]
    public function findByCriteriaForUpdateFiltersByStatus(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('active-1', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('booting-1', RunMode::FROM_BEGINNING, SubscriptionStatus::BOOTING));
        $this->store->add(Subscription::create('active-2', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        $result = $this->store->findByCriteriaForUpdate(SubscriptionCriteria::create(status: [SubscriptionStatus::ACTIVE]));
        self::assertSameSubscriptionIds(['active-1', 'active-2'], $result);
    }

    #[Test]
    public function findByCriteriaForUpdateCombinesIdAndStatusConstraints(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('bar', RunMode::FROM_BEGINNING, SubscriptionStatus::BOOTING));
        $this->store->add(Subscription::create('baz', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        $result = $this->store->findByCriteriaForUpdate(SubscriptionCriteria::create(ids: ['foo', 'bar'], status: [SubscriptionStatus::ACTIVE]));
        self::assertSameSubscriptionIds(['foo'], $result);
    }

    #[Test]
    public function findByCriteriaReturnsNoneWhenStoreIsEmpty(): void
    {
        $this->store->setup();

        self::assertSameSubscriptionIds([], $this->store->findByCriteria(SubscriptionCriteria::noConstraints()));
    }

    #[Test]
    public function findByCriteriaReturnsSubscriptionsOrderedById(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('charlie', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('alpha', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('bravo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        self::assertSameSubscriptionIds(['alpha', 'bravo', 'charlie'], $this->store->findByCriteria(SubscriptionCriteria::noConstraints()));
    }

    #[Test]
    public function findByCriteriaFiltersByIds(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('bar', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('baz', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        $result = $this->store->findByCriteria(SubscriptionCriteria::create(ids: ['foo', 'baz']));
        self::assertSameSubscriptionIds(['baz', 'foo'], $result);
    }

    #[Test]
    public function findByCriteriaFiltersByStatus(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('active-1', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('booting-1', RunMode::FROM_BEGINNING, SubscriptionStatus::BOOTING));
        $this->store->add(Subscription::create('active-2', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        $result = $this->store->findByCriteria(SubscriptionCriteria::create(status: [SubscriptionStatus::ACTIVE]));
        self::assertSameSubscriptionIds(['active-1', 'active-2'], $result);
    }

    #[Test]
    public function findByCriteriaCombinesIdAndStatusConstraints(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('bar', RunMode::FROM_BEGINNING, SubscriptionStatus::BOOTING));
        $this->store->add(Subscription::create('baz', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));

        $result = $this->store->findByCriteria(SubscriptionCriteria::create(ids: ['foo', 'bar'], status: [SubscriptionStatus::ACTIVE]));
        self::assertSameSubscriptionIds(['foo'], $result);
    }

    #[Test]
    public function findByCriteriaAndFindByCriteriaForUpdateReturnTheSameResult(): void
    {
        $this->store->setup();
        $this->store->add(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->add(Subscription::create('bar', RunMode::FROM_BEGINNING, SubscriptionStatus::BOOTING));

        $criteria = SubscriptionCriteria::create(status: [SubscriptionStatus::BOOTING]);
        self::assertSameSubscriptionIds(
            $this->store->findByCriteriaForUpdate($criteria)->map(static fn(Subscription $s): string => $s->id->value),
            $this->store->findByCriteria($criteria),
        );
    }

    #[Test]
    public function transactionCommitPersistsChanges(): void
    {
        $this->store->setup();

        $this->store->beginTransaction();
        $this->store->add(Subscription::create('foo', RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE));
        $this->store->commit();

        self::assertSameSubscriptionIds(['foo'], $this->store->findByCriteriaForUpdate(SubscriptionCriteria::noConstraints()));
    }

    /**
     * @param array<string> $expectedIds
     */
    private static function assertSameSubscriptionIds(array $expectedIds, Subscriptions $subscriptions): void
    {
        self::assertSame(
            $expectedIds,
            $subscriptions->map(static fn(Subscription $subscription): string => $subscription->id->value),
        );
    }

    private function fixedClock(DateTimeImmutable $now): ClockInterface
    {
        return new class ($now) implements ClockInterface {
            public function __construct(private readonly DateTimeImmutable $now) {}

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
