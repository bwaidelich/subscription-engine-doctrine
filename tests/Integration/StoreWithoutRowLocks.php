<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionStore;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;

/**
 * Negative control: Proves that the integration test detects double handling
 */
final readonly class StoreWithoutRowLocks implements SubscriptionStore
{
    public function __construct(
        private SubscriptionStore $store,
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
        return $this->store->findByCriteria($criteria);
    }

    public function claimNext(SubscriptionCriteria $criteria): Subscription|null
    {
        $subscriptions = iterator_to_array($this->store->findByCriteria($criteria), false);
        usort($subscriptions, static fn(Subscription $a, Subscription $b) => [$a->position->value, $a->id->value] <=> [$b->position->value, $b->id->value]);
        return $subscriptions[0] ?? null;
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
        $this->store->beginTransaction();
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
