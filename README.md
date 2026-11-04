# subscription-engine-doctrine
Doctrine DBAL adapters for the [wwwision/subscription-engine](https://packagist.org/packages/wwwision/subscription-engine)

## Transactions

On MySQL and MariaDB, `beginTransaction()` starts a transaction with the isolation level `READ COMMITTED`.
With `REPEATABLE READ`, workers that wait for a subscription lock can deadlock with a worker that changes the status of a subscription.
The level only applies to that transaction, not to the session. A transaction that is already active keeps its level.
Subscriber handlers that share the connection run in the same transaction.

MySQL and MariaDB roll back the whole transaction of a deadlock victim. If that happens inside a subscriber handler, `add()` and `update()` throw an exception instead of writing outside of the transaction, and the engine repeats the batch on the next catch-up. This relies on `PDO::inTransaction()`, so it requires a PDO driver: With `mysqli` it is not detected.

DBAL closes a connection on its own if a statement reports that it is lost, and the next statement reconnects without a transaction. `add()` and `update()` throw an exception in that case as well, with any driver. Call `close()` on the connection if the exception doesn't come from DBAL recognizing the lost connection: The next statement then reconnects.

## Claims

`claimNext()` requires MySQL 8.0, MariaDB 10.6 or PostgreSQL. On older versions of MySQL and MariaDB, DBAL throws a `NotSupported` exception instead of silently waiting for locks. `catchUpActive()` and `boot()` keep working.

- It reads the candidates without locks and then locks them one by one, because a single query with `ORDER BY` and `LIMIT` locks all matching rows on MySQL and MariaDB. This takes one query for the candidates and one per candidate it tries
- SQLite has no row locks. `beginTransaction()` already makes a second worker wait until the first one is done, so workers run one after another. Each event is still handled once, and `allSubscriptionsLocked` is never reported
- MySQL considers `SKIP LOCKED` unsafe for statement-based replication

## Tests

`composer test` skips the tests in `tests/Integration` unless `TEST_DATABASE_DSN` is set. They run worker processes against a real database and drop and create the tables `events`, `subscriptions`, `projection`, `contention` and `filler`, so use a dedicated database:

```shell
docker run -d --rm --name subscription-engine-test -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=test -p 127.0.0.1:23306:3306 mysql:8.4
TEST_DATABASE_DSN='pdo-mysql://root:test@127.0.0.1:23306/test' composer test:phpunit
```
