<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\DefaultSchemaManagerFactory;
use Doctrine\DBAL\Tools\DsnParser;
use RuntimeException;
use Throwable;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpFinished;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpInitiated;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\CatchUpStarted;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\EngineEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriberHandledEvent;
use Wwwision\SubscriptionEngine\Engine\EngineEvent\SubscriptionActivated;
use Wwwision\SubscriptionEngine\Engine\SubscriptionEngineCriteria;
use Wwwision\SubscriptionEngine\Subscriber\Subscriber;
use Wwwision\SubscriptionEngine\Subscriber\Subscribers;
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * One worker process of {@see ConcurrentCatchUpTest}, the configuration is passed as JSON argument and the outcome of each run is appended to a shared JSONL file
 */

$config = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$log = fopen($config['log'], 'ab');
$write = static function (array $entry) use ($log, $config): void {
    fwrite($log, json_encode($entry + ['worker' => $config['name']]) . "\n");
    fflush($log);
};

$dbalConfiguration = new Configuration();
$dbalConfiguration->setSchemaManagerFactory(new DefaultSchemaManagerFactory());
$connection = DriverManager::getConnection((new DsnParser())->parse((string) getenv('TEST_DATABASE_DSN')), $dbalConfiguration);
$eventStore = new DbalEventStoreAdapter($connection);
while (!file_exists($config['go'])) {
    usleep(1000);
}
usleep(($config['delayMs'] ?? 0) * 1000);

if ($config['mode'] === 'writer') {
    for ($i = 0; $i < $config['count']; $i++) {
        $eventStore->append(1);
        usleep($config['sleepUs']);
    }
    exit(0);
}
if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
    $connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 5');
}

$waitFor = static function (string $file): void {
    $deadline = microtime(true) + 20;
    while (!file_exists($file)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timeout waiting for ' . $file);
        }
        usleep(1000);
    }
};

if ($config['mode'] === 'deadlockRival') {
    // holds the lock the handler needs next and waits for the lock the handler holds. The many written rows make the handler the cheaper deadlock victim
    try {
        $connection->beginTransaction();
        for ($i = 0; $i < 300; $i++) {
            $connection->insert('filler', ['value' => $i]);
        }
        $connection->executeStatement('UPDATE contention SET value = value + 1 WHERE id = 2');
        touch($config['rivalHolds']);
        $waitFor($config['handlerHolds']);
        $connection->executeStatement('UPDATE contention SET value = value + 1 WHERE id = 1');
        $connection->commit();
    } catch (Throwable $e) {
        $write(['rivalFailed' => $e::class]);
    }
    exit(0);
}

$handlerCalls = 0;
$subscribers = [];
foreach ($config['subscriptions'] as $id) {
    $subscribers[] = Subscriber::create($id, static function (TestEvent $event) use ($id, $connection, $config, $waitFor, $write, &$handlerCalls): void {
        $handlerCalls++;
        if (($config['deadlockAt']['subscription'] ?? null) === $id && $config['deadlockAt']['position'] === $event->position->value) {
            $connection->executeStatement('UPDATE contention SET value = value + 1 WHERE id = 1');
            touch($config['handlerHolds']);
            $waitFor($config['rivalHolds']);
            usleep(500000);
            try {
                $connection->executeStatement('UPDATE contention SET value = value + 1 WHERE id = 2');
            } catch (Throwable $e) {
                $write(['handlerFailed' => $e::class]);
                throw $e;
            }
        }
        if (($config['sqlErrorAt']['subscription'] ?? null) === $id && $config['sqlErrorAt']['position'] === $event->position->value) {
            $connection->executeQuery('SELECT * FROM table_that_does_not_exist');
        }
        if (($config['killAtHandlerCall'] ?? null) === $handlerCalls) {
            posix_kill(getmypid(), 9);
            sleep(5);
        }
        if (($config['poison']['subscription'] ?? null) === $id && $config['poison']['position'] === $event->position->value) {
            throw new RuntimeException('poison');
        }
        $previousPosition = (int) $connection->fetchOne('SELECT MAX(position) FROM projection WHERE subscription = ?', [$id]);
        if ($previousPosition !== $event->position->value - 1) {
            $write(['outOfOrder' => sprintf('%s handled event %d after event %d', $id, $event->position->value, $previousPosition)]);
        }
        $connection->insert('projection', ['subscription' => $id, 'position' => $event->position->value, 'worker' => $config['name']]);
        usleep($config['sleepUs'] ?? 0);
    });
}
$store = new DoctrineSubscriptionStore($connection, 'subscriptions');
$store = match ($config['store'] ?? null) {
    'withoutRowLocks' => new StoreWithoutRowLocks($store),
    'repeatableRead' => new StoreWithRepeatableRead($store, $connection),
    default => $store,
};
$engine = new SubscriptionEngine($eventStore, $store, Subscribers::fromArray($subscribers));

$run = [];
$failuresLeft = $config['failAfterHandledEvent'] ?? 0;
$engine->onEngineEvent(static function (EngineEvent $event) use (&$run, &$failuresLeft): void {
    $now = microtime(true);
    match (true) {
        $event instanceof CatchUpInitiated => $run['initiated'] = $now,
        $event instanceof CatchUpStarted => $run['started'] = $now,
        $event instanceof CatchUpFinished => $run['finished'] = $now,
        $event instanceof SubscriptionActivated => $run['activated'] = ($run['activated'] ?? 0) + 1,
        default => null,
    };
    if ($failuresLeft > 0 && $event instanceof SubscriberHandledEvent) {
        $failuresLeft--;
        throw new RuntimeException('Exception escaping the catch-up');
    }
});

$catchUp = static function (string $mode) use ($engine, $config) {
    $criteria = isset($config['ids']) ? SubscriptionEngineCriteria::create($config['ids']) : null;
    return match ($mode) {
        'boot' => $engine->boot($criteria, $config['limit']),
        'bootNext' => $engine->bootNext($criteria, $config['limit']),
        'activeNext' => $engine->catchUpNextActive($criteria, $config['limit']),
        default => $engine->catchUpActive($criteria, $config['limit']),
    };
};

if ($config['mode'] === 'keepGoing') {
    // a long-running worker: log every exception and carry on with the same engine and connection
    $processed = 0;
    $caught = [];
    while (!file_exists($config['stop'])) {
        foreach (['boot', 'active'] as $mode) {
            try {
                $processed += $catchUp($mode)->numberOfProcessedEvents;
            } catch (Throwable $e) {
                $caught[$e::class] = ($caught[$e::class] ?? 0) + 1;
            }
        }
        usleep(2000);
    }
    do {
        $catchUp('boot');
        $result = $catchUp('active');
        $processed += $result->numberOfProcessedEvents;
    } while ($result->hasPendingEvents);
    $write(['processed' => $processed, 'caught' => $caught, 'keepGoing' => true]);
    exit(0);
}

try {
    while (true) {
        $run = [];
        $result = $catchUp($config['mode']);
        $write($run + ['processed' => $result->numberOfProcessedEvents, 'pending' => $result->hasPendingEvents, 'errors' => $result->errors?->count() ?? 0]);
        if ($result->hasPendingEvents) {
            continue;
        }
        if (!isset($config['stop']) || (file_exists($config['stop']) && $result->numberOfProcessedEvents === 0)) {
            break;
        }
        usleep(5000);
    }
} catch (Throwable $e) {
    $write(['fatal' => $e::class . ': ' . $e->getMessage()]);
    exit(3);
}
