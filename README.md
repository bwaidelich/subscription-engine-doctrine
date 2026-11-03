# subscription-engine-doctrine
Doctrine DBAL adapters for the [wwwision/subscription-engine](https://packagist.org/packages/wwwision/subscription-engine)

## Transactions

On MySQL and MariaDB, `beginTransaction()` starts a transaction with the isolation level `READ COMMITTED`.
With `REPEATABLE READ`, workers that wait for a subscription lock can deadlock with a worker that changes the status of a subscription.
The level only applies to that transaction, not to the session. A transaction that is already active keeps its level.
Subscriber handlers that share the connection run in the same transaction.

MySQL and MariaDB roll back the whole transaction of a deadlock victim. If that happens inside a subscriber handler, `add()` and `update()` throw an exception instead of writing outside of the transaction, and the engine repeats the batch on the next catch-up. This relies on `PDO::inTransaction()`, so it requires a PDO driver: With `mysqli` it is not detected.

## Tests

`composer test` skips the tests in `tests/Integration` unless `TEST_DATABASE_DSN` is set. They run worker processes against a real database and drop and create the tables `events`, `subscriptions`, `projection`, `contention` and `filler`, so use a dedicated database:

```shell
docker run -d --rm --name subscription-engine-test -e MYSQL_ROOT_PASSWORD=test -e MYSQL_DATABASE=test -p 127.0.0.1:23306:3306 mysql:8.4
TEST_DATABASE_DSN='pdo-mysql://root:test@127.0.0.1:23306/test' composer test:phpunit
```
