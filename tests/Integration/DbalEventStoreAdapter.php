<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Wwwision\SubscriptionEngine\EventStore\EventStoreAdapter;
use Wwwision\SubscriptionEngine\EventStore\ReadOptions;
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

    public function read(Position $startPosition, ReadOptions $options): iterable
    {
        if ($options->filter !== null) {
            throw new InvalidArgumentException('Filters are not supported', 1794470800);
        }
        $sql = 'SELECT position FROM events WHERE position >= ?' . ($options->upTo !== null ? ' AND position <= ' . $options->upTo->value : '') . ' ORDER BY position' . ($options->limit !== null ? ' LIMIT ' . $options->limit : '');
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

    public function matches(object $filter, object $event): bool
    {
        throw new InvalidArgumentException('Filters are not supported', 1794470801);
    }

    public function append(int $numberOfEvents): void
    {
        for ($done = 0; $done < $numberOfEvents; $done += 500) {
            $this->connection->executeStatement('INSERT INTO events (payload) VALUES ' . implode(',', array_fill(0, min(500, $numberOfEvents - $done), "('{}')")));
        }
    }
}
