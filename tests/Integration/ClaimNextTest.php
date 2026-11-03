<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;

/**
 * Deterministic tests of {@see DoctrineSubscriptionStore::claimNext()} with one connection per simulated worker, without timing.
 * Each connection is one store with a transaction that stays open until the end of the test.
 *
 * Requires the environment variable TEST_DATABASE_DSN, for example: pdo-mysql://root:password@127.0.0.1:3306/test
 */
#[CoversClass(DoctrineSubscriptionStore::class)]
final class ClaimNextTest extends TestCase
{
    /**
     * @var array<DoctrineSubscriptionStore>
     */
    private array $openStores = [];

    private DoctrineSubscriptionStore $store;

    protected function setUp(): void
    {
        $dsn = getenv('TEST_DATABASE_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('TEST_DATABASE_DSN is not set');
        }
        $connection = self::connect($dsn);
        $connection->executeStatement('DROP TABLE IF EXISTS subscriptions');
        $this->store = new DoctrineSubscriptionStore($connection, 'subscriptions');
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
    public function returnsTheLowestPositionFirstAndBreaksTiesById(): void
    {
        $this->addSubscriptions(['s1' => 30, 's2' => 10, 's4' => 20, 's3' => 20]);

        self::assertSame(['s2', 's3', 's4', 's1', null], $this->claimUntilExhausted(5, $this->activeSubscriptions()));
    }

    #[Test]
    public function returnsNullIfAllSubscriptionsAreLocked(): void
    {
        $this->addSubscriptions(['s1' => 0]);

        self::assertSame(['s1', null], $this->claimUntilExhausted(2, $this->activeSubscriptions()));
    }

    #[Test]
    public function locksNothingButTheClaimedSubscriptionInALargerTable(): void
    {
        $subscriptions = [];
        for ($i = 100; $i < 160; $i++) {
            $subscriptions['s' . $i] = 260 - $i;
        }
        $this->addSubscriptions($subscriptions);

        self::assertSame(['s159', 's158', 's157'], $this->claimUntilExhausted(3, $this->activeSubscriptions()));
    }

    #[Test]
    public function locksNothingButTheClaimedSubscriptionIfCriteriaContainIds(): void
    {
        $subscriptions = [];
        for ($i = 100; $i < 160; $i++) {
            $subscriptions['s' . $i] = 260 - $i;
        }
        $this->addSubscriptions($subscriptions);

        $criteria = SubscriptionCriteria::create(['s101', 's102', 's103'], [SubscriptionStatus::ACTIVE]);

        self::assertSame(['s103', 's102', 's101', null], $this->claimUntilExhausted(4, $criteria));
    }

    #[Test]
    public function ignoresSubscriptionsThatDoNotMatchTheCriteria(): void
    {
        $this->store->add(Subscription::create('booting', RunMode::FROM_BEGINNING, SubscriptionStatus::BOOTING));
        $this->addSubscriptions(['s1' => 5]);

        self::assertSame(['s1', null], $this->claimUntilExhausted(2, $this->activeSubscriptions()));
    }

    #[Test]
    public function isAvailableAgainAfterACommitWithTheNewPosition(): void
    {
        $this->addSubscriptions(['s1' => 0, 's2' => 5]);
        $worker = $this->openStore();
        $subscription = $worker->claimNext($this->activeSubscriptions());
        self::assertNotNull($subscription);
        $worker->update($subscription->with(position: Position::fromInteger(9)));
        $worker->commit();
        array_pop($this->openStores);

        self::assertSame(['s2', 's1', null], $this->claimUntilExhausted(3, $this->activeSubscriptions()));
    }

    #[Test]
    public function isAvailableAgainAfterARollback(): void
    {
        $this->addSubscriptions(['s1' => 0]);
        $worker = $this->openStore();
        self::assertNotNull($worker->claimNext($this->activeSubscriptions()));
        $worker->rollback();
        array_pop($this->openStores);

        self::assertSame(['s1'], $this->claimUntilExhausted(1, $this->activeSubscriptions()));
    }

    #[Test]
    public function skipsSubscriptionsThatAreLockedByABlockingCatchUp(): void
    {
        $this->addSubscriptions(['s1' => 0, 's2' => 5]);
        $blocking = $this->openStore();
        $blocking->findByCriteriaForUpdate(SubscriptionCriteria::create(['s1']));

        self::assertSame(['s2', null], $this->claimUntilExhausted(2, $this->activeSubscriptions()));
    }

    #[Test]
    public function aPlainReadDoesNotWaitForAClaim(): void
    {
        $this->addSubscriptions(['s1' => 0]);
        $worker = $this->openStore();
        self::assertNotNull($worker->claimNext($this->activeSubscriptions()));

        self::assertCount(1, $this->store->findByCriteria($this->activeSubscriptions()));
    }

    // ------------------------------

    /**
     * @return array<string|null> The id each new worker claimed
     */
    private function claimUntilExhausted(int $numberOfWorkers, SubscriptionCriteria $criteria): array
    {
        $claimed = [];
        for ($i = 0; $i < $numberOfWorkers; $i++) {
            $claimed[] = $this->openStore()->claimNext($criteria)?->id->value;
        }
        return $claimed;
    }

    private function openStore(): DoctrineSubscriptionStore
    {
        $store = new DoctrineSubscriptionStore(self::connect((string) getenv('TEST_DATABASE_DSN')), 'subscriptions');
        $store->beginTransaction();
        $this->openStores[] = $store;
        return $store;
    }

    /**
     * @param array<string, int> $positionsById
     */
    private function addSubscriptions(array $positionsById): void
    {
        foreach ($positionsById as $id => $position) {
            $this->store->add(Subscription::create($id, RunMode::FROM_BEGINNING, SubscriptionStatus::ACTIVE)->with(position: Position::fromInteger($position)));
        }
    }

    private function activeSubscriptions(): SubscriptionCriteria
    {
        return SubscriptionCriteria::create(null, [SubscriptionStatus::ACTIVE]);
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
