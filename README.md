# Doctrine DBAL store for the Subscription Engine

[![Latest version](https://img.shields.io/packagist/v/wwwision/subscription-engine-doctrine)](https://packagist.org/packages/wwwision/subscription-engine-doctrine)
[![License](https://img.shields.io/packagist/l/wwwision/subscription-engine-doctrine)](./LICENSE)

**Store the state of your subscriptions in the database you already use.**

This package is a subscription store for the [Subscription Engine](https://github.com/bwaidelich/subscription-engine), based on [Doctrine DBAL](https://www.doctrine-project.org/projects/dbal.html).

| Database | Supported | Parallel workers |
|---|---|---|
| PostgreSQL | ✅ | ✅ |
| MySQL | ✅ | ✅ from 8.0 |
| MariaDB | ✅ | ✅ from 10.6 |
| SQLite | ✅ | Workers run one after another |

## Installation

```bash
composer require wwwision/subscription-engine-doctrine
```

## Usage

Pass the store to the engine, together with an [event store adapter](https://github.com/bwaidelich/subscription-engine#which-packages-do-i-need):

```php
use Wwwision\SubscriptionEngine\SubscriptionEngine;
use Wwwision\SubscriptionEngineDoctrine\DoctrineSubscriptionStore;

$engine = new SubscriptionEngine(
    eventStoreAdapter: $eventStoreAdapter,
    subscriptionStore: new DoctrineSubscriptionStore($connection, tableName: 'subscriptions'),
    subscribers: $subscribers,
);

$engine->setup();
```

`$engine->setup()` creates the table, and adjusts it if a new version of this package requires that. There is no need for a separate migration.

The [Subscription Engine quick start](https://github.com/bwaidelich/subscription-engine#quick-start) shows a complete example.

### Save projections and progress together

Use the same `$connection` for the store and for everything your subscribers write:

```php
Subscriber::create(
    id: 'product-list',
    handler: fn (SequencedEvent $event) => $connection->insert('product_list', [/* ... */]),
);
```

The changes of a subscriber are then part of the same transaction as the position of its subscription, so that each event counts exactly once, even if a process gets killed.

### Provide your own clock

The store saves the time of the last change of a subscription. To control that time, for example in tests, pass any [PSR-20](https://www.php-fig.org/psr/psr-20/) clock:

```php
new DoctrineSubscriptionStore($connection, 'subscriptions', $clock);
```

## Technical details

You don't need these details to get started.

### Transactions

On MySQL and MariaDB, `beginTransaction()` starts a transaction with the isolation level `READ COMMITTED`.
With `REPEATABLE READ`, workers that wait for a subscription lock can deadlock with a worker that changes the status of a subscription.
The level only applies to that transaction, not to the session. A transaction that is already active keeps its level.
Subscriber handlers that share the connection run in the same transaction.

MySQL and MariaDB roll back the whole transaction of a deadlock victim. If that happens inside a subscriber handler, `add()` and `update()` throw an exception instead of writing outside of the transaction, and the engine repeats the batch on the next catch-up. This relies on `PDO::inTransaction()`, so it requires a PDO driver: With `mysqli` it is not detected.

DBAL closes a connection on its own if a statement reports that it is lost, and the next statement reconnects without a transaction. `add()` and `update()` throw an exception in that case as well, with any driver. Call `close()` on the connection if the exception doesn't come from DBAL recognizing the lost connection: The next statement then reconnects.

### Claims

`claimNext()` requires MySQL 8.0, MariaDB 10.6 or PostgreSQL. On older versions of MySQL and MariaDB, DBAL throws a `NotSupported` exception instead of silently waiting for locks. `catchUpActive()` and `boot()` keep working.

- It reads the candidates without locks and then locks them one by one, because a single query with `ORDER BY` and `LIMIT` locks all matching rows on MySQL and MariaDB. This takes one query for the candidates and one per candidate it tries
- SQLite has no row locks. `beginTransaction()` already makes a second worker wait until the first one is done, so workers run one after another. Each event is still handled once, and `allSubscriptionsLocked` is never reported
- MySQL considers `SKIP LOCKED` unsafe for statement-based replication

## Contribution

Contributions in the form of [issues](https://github.com/bwaidelich/subscription-engine-doctrine/issues) or [pull requests](https://github.com/bwaidelich/subscription-engine-doctrine/pulls) are highly appreciated

`composer test` skips the tests in `tests/Integration` unless `TEST_DATABASE_DSN` is set. They run worker processes against a real database and drop and create the tables `events`, `subscriptions`, `projection`, `contention` and `filler`, so use a dedicated database:

```shell
docker run -d --rm --name subscription-engine-test -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=test -p 127.0.0.1:23306:3306 mysql:8.4
TEST_DATABASE_DSN='pdo-mysql://root:test@127.0.0.1:23306/test' composer test:phpunit
```

## License

See [LICENSE](./LICENSE)
