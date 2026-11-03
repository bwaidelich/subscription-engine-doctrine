# subscription-engine-doctrine
Doctrine DBAL adapters for the [wwwision/subscription-engine](https://packagist.org/packages/wwwision/subscription-engine)

## Deadlocks in subscriber handlers

MySQL and MariaDB roll back the whole transaction of a deadlock victim. If that happens inside a subscriber handler, `add()` and `update()` throw an exception instead of writing outside of the transaction, so nothing of that catch-up is persisted.

Restart the worker afterwards: Before version 2 of the subscription engine, the engine and the connection can't be used for further catch-ups after such an exception.

This relies on `PDO::inTransaction()`, so it requires a PDO driver: With `mysqli` it is not detected.
