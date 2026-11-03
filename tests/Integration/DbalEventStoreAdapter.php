<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Doctrine\DBAL\Connection;
use Wwwision\SubscriptionEngine\EventStore\EventStoreAdapter;
use Wwwision\SubscriptionEngine\Subscription\Position;

/**
 * Minimal event store on the connection of the subscription store, as it is common for projections that are updated in the same transaction
 *
 * @implements EventStoreAdapter<TestEvent>
 */
final readonly class DbalEventStoreAdapter implements EventStoreAdapter
{
    public function __construct(
        private Connection $connection,
    ) {}

    public function read(Position $startPosition, int|null $limit = null): iterable
    {
        $sql = 'SELECT position FROM events WHERE position >= ? ORDER BY position' . ($limit !== null ? ' LIMIT ' . $limit : '');
        foreach ($this->connection->fetchFirstColumn($sql, [$startPosition->value]) as $position) {
            yield new TestEvent(Position::fromInteger((int) $position));
        }
    }

    public function lastPosition(): Position
    {
        return Position::fromInteger((int) $this->connection->fetchOne('SELECT COALESCE(MAX(position), 0) FROM events'));
    }

    public function eventPosition(object $event): Position
    {
        return $event->position;
    }

    public function append(int $numberOfEvents): void
    {
        for ($done = 0; $done < $numberOfEvents; $done += 500) {
            $this->connection->executeStatement('INSERT INTO events (payload) VALUES ' . implode(',', array_fill(0, min(500, $numberOfEvents - $done), "('{}')")));
        }
    }
}
