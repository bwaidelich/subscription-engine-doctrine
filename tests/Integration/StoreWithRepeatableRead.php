<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Doctrine\DBAL\Connection;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionStore;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;

/**
 * Starts transactions with the default isolation level of MySQL and MariaDB to provoke real deadlocks, everything else is up to the actual store
 */
final readonly class StoreWithRepeatableRead implements SubscriptionStore
{
    public function __construct(
        private SubscriptionStore $store,
        private Connection $connection,
    ) {}

    public function setup(): void
    {
        $this->store->setup();
    }

    public function findByCriteria(SubscriptionCriteria $criteria): Subscriptions
    {
        return $this->store->findByCriteria($criteria);
    }

    public function findByCriteriaForUpdate(SubscriptionCriteria $criteria): Subscriptions
    {
        return $this->store->findByCriteriaForUpdate($criteria);
    }

    public function add(Subscription $subscription): void
    {
        $this->store->add($subscription);
    }

    public function update(Subscription $subscription): void
    {
        $this->store->update($subscription);
    }

    public function beginTransaction(): void
    {
        $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->connection->beginTransaction();
    }

    public function commit(): void
    {
        $this->store->commit();
    }

    public function rollback(): void
    {
        $this->store->rollback();
    }
}
