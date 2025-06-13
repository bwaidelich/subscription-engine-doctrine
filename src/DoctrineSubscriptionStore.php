<?php

declare(strict_types=1);

namespace Wwwision\SubscriptionEngineDoctrine;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Wwwision\SubscriptionEngine\Store\SubscriptionCriteria;
use Wwwision\SubscriptionEngine\Store\SubscriptionStore;
use Wwwision\SubscriptionEngine\Subscription\Position;
use Wwwision\SubscriptionEngine\Subscription\RunMode;
use Wwwision\SubscriptionEngine\Subscription\Subscription;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionError;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionId;
use Wwwision\SubscriptionEngine\Subscription\Subscriptions;
use Wwwision\SubscriptionEngine\Subscription\SubscriptionStatus;

final class DoctrineSubscriptionStore implements SubscriptionStore
{
    private readonly ClockInterface $clock;

    public function __construct(
        private readonly Connection $dbal,
        private readonly string $tableName,
        ClockInterface|null $clock = null,
    ) {
        $this->clock = $clock ?? new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable();
            }
        };
    }

    public function setup(): void
    {
        try {
            $schemaManager = $this->dbal->createSchemaManager();
            $schemaDiff = $schemaManager->createComparator()->compareSchemas($schemaManager->introspectSchema(), $this->databaseSchema());
            foreach (self::toSaveSql($this->dbal->getDatabasePlatform(), $schemaDiff) as $statement) {
                $this->dbal->executeStatement($statement);
            }
        } catch (DbalException $e) {
            throw new RuntimeException(sprintf('Failed to setup subscription store: %s', $e->getMessage()), 1736174563, $e);
        }
    }

    public function findByCriteriaForUpdate(SubscriptionCriteria $criteria): Subscriptions
    {
        $queryBuilder = $this->dbal->createQueryBuilder()
            ->select('*')
            ->from($this->tableName)
            ->orderBy('id');
        if (!$this->dbal->getDatabasePlatform() instanceof SQLitePlatform) {
            $queryBuilder->forUpdate();
        }
        if ($criteria->ids !== null) {
            $queryBuilder->andWhere('id IN (:ids)')
                ->setParameter(
                    'ids',
                    $criteria->ids->toStringArray(),
                    ArrayParameterType::STRING,
                );
        }
        if ($criteria->status !== null) {
            $queryBuilder->andWhere('status IN (:status)')
                ->setParameter(
                    'status',
                    $criteria->status->toStringArray(),
                    ArrayParameterType::STRING,
                );
        }
        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();
        if ($rows === []) {
            return Subscriptions::none();
        }
        return Subscriptions::fromArray(array_map(self::fromDatabase(...), $rows));
    }

    public function add(Subscription $subscription): void
    {
        $row = self::toDatabase($subscription);
        $row['id'] = $subscription->id->value;
        $row['last_saved_at'] = $this->clock->now()->format('Y-m-d H:i:s');
        $this->dbal->insert(
            $this->tableName,
            $row,
        );
    }

    public function update(Subscription $subscription): void
    {
        $row = self::toDatabase($subscription);
        $row['last_saved_at'] = $this->clock->now()->format('Y-m-d H:i:s');
        $this->dbal->update(
            $this->tableName,
            $row,
            [
                'id' => $subscription->id->value,
            ]
        );
    }

    public function beginTransaction(): void
    {
        if ($this->dbal->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->dbal->executeStatement('BEGIN EXCLUSIVE');
        } else {
            $this->dbal->beginTransaction();
        }
    }

    public function commit(): void
    {
        if ($this->dbal->getDatabasePlatform() instanceof SQLitePlatform) {
            $this->dbal->executeStatement('COMMIT');
        } else {
            $this->dbal->commit();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function toDatabase(Subscription $subscription): array
    {
        return [
            'status' => $subscription->status->value,
            'run_mode' => $subscription->runMode->value,
            'position' => $subscription->position->value,
            'error_message' => $subscription->error?->errorMessage,
            'error_previous_status' => $subscription->error?->previousStatus?->value,
            'error_trace' => $subscription->error?->errorTrace,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromDatabase(array $row): Subscription
    {
        assert(is_string($row['id']));
        assert(is_string($row['run_mode']));
        assert(is_string($row['status']));
        assert(is_int($row['position']));
        assert(is_string($row['last_saved_at']));
        if (isset($row['error_message'])) {
            assert(is_string($row['error_message']));
            assert(is_string($row['error_previous_status']));
            assert(is_string($row['error_trace']));
            $subscriptionError = new SubscriptionError($row['error_message'], SubscriptionStatus::from($row['error_previous_status']), $row['error_trace']);
        } else {
            $subscriptionError = null;
        }
        $lastSavedAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['last_saved_at']);
        if ($lastSavedAt === false) {
            throw new RuntimeException(sprintf('last_saved_at %s is not a valid date', $row['last_saved_at']), 1733602968);
        }

        return new Subscription(
            SubscriptionId::fromString($row['id']),
            RunMode::from($row['run_mode']),
            SubscriptionStatus::from($row['status']),
            Position::fromInteger($row['position']),
            $subscriptionError,
            $lastSavedAt,
        );
    }

    /**
     * @throws SchemaException
     */
    private function databaseSchema(): Schema
    {
        $tableSchema = new Table($this->tableName, [
            (new Column('id', Type::getType(Types::STRING)))->setNotnull(true)->setLength(SubscriptionId::MAX_LENGTH),
            (new Column('run_mode', Type::getType(Types::STRING)))->setNotnull(true)->setLength(32),
            (new Column('status', Type::getType(Types::STRING)))->setNotnull(true)->setLength(32),
            (new Column('position', Type::getType(Types::INTEGER)))->setNotnull(true),
            (new Column('error_message', Type::getType(Types::TEXT)))->setNotnull(false),
            (new Column('error_previous_status', Type::getType(Types::STRING)))->setNotnull(false)->setLength(32),
            (new Column('error_trace', Type::getType(Types::TEXT)))->setNotnull(false),
            (new Column('last_saved_at', Type::getType(Types::DATETIME_IMMUTABLE)))->setNotnull(true),
        ]);
        $tableSchema->setPrimaryKey(['id']);
        $tableSchema->addIndex(['status']);
        $schemaConfig = $this->dbal->createSchemaManager()->createSchemaConfig();
        $schemaConfig->setDefaultTableOptions([
            'charset' => 'utf8mb4'
        ]);
        return new Schema([$tableSchema], [], $schemaConfig);
    }

    /**
     * @return array<string>
     * @throws DbalException
     */
    private static function toSaveSql(AbstractPlatform $platform, SchemaDiff $schemaDiff): array
    {
        $sql = [];
        if ($platform->supportsSchemas()) {
            foreach ($schemaDiff->getCreatedSchemas() as $schema) {
                $sql[] = $platform->getCreateSchemaSQL($schema);
            }
        }
        if ($platform->supportsSequences() === true) {
            foreach ($schemaDiff->getAlteredSequences() as $sequence) {
                $sql[] = $platform->getAlterSequenceSQL($sequence);
            }
            foreach ($schemaDiff->getCreatedSequences() as $sequence) {
                $sql[] = $platform->getCreateSequenceSQL($sequence);
            }
        }
        $sql = array_merge($sql, $platform->getCreateTablesSQL($schemaDiff->getCreatedTables()));
        foreach ($schemaDiff->getAlteredTables() as $tableDiff) {
            foreach ($platform->getAlterTableSQL($tableDiff) as $statement) {
                $sql[] = $statement;
            }
        }
        return $sql;
    }
}
