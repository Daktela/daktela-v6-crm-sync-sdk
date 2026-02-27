<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\EntityInterface;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\State\SyncStateStoreInterface;
use Daktela\CrmSync\Sync\Result\RecordResult;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Psr\Log\LoggerInterface;

final class BatchSync
{
    /** @var array<string, array<string, string>> */
    private array $relationMaps = [];

    /** @var array<string, true> Keys are "entityType:crmId" */
    private array $syncingEntities = [];

    /** @var array<string, int> Tracks pagination offset per entity type */
    private array $offsets = [];

    private bool $forceFullSync = false;

    public function __construct(
        private readonly ContactCentreAdapterInterface $ccAdapter,
        private readonly CrmAdapterInterface $crmAdapter,
        private readonly FieldMapper $fieldMapper,
        private readonly SyncConfiguration $config,
        private readonly LoggerInterface $logger,
        private readonly ?SyncStateStoreInterface $stateStore = null,
    ) {
    }

    public function setForceFullSync(bool $force): void
    {
        $this->forceFullSync = $force;
    }

    public function resetOffsets(): void
    {
        $this->offsets = [];
    }

    /**
     * Builds relation resolution maps by scanning mapping configs for relation definitions,
     * then iterating the relevant source entities to build CRM-ID → CC-ID maps.
     *
     * Optional: pre-populates the map for efficiency. Missing relations are auto-resolved on-the-fly.
     */
    public function buildRelationMaps(): void
    {
        $this->relationMaps = [];

        // Scan contact mappings for relation fields
        $contactMapping = $this->config->getMapping('contact');
        if ($contactMapping !== null) {
            foreach ($contactMapping->mappings as $mapping) {
                if ($mapping->relation === null) {
                    continue;
                }

                $this->buildRelationMap($mapping->relation);
            }
        }
    }

    /**
     * Returns the current relation maps (useful for webhook sync).
     *
     * @return array<string, array<string, string>>
     */
    public function getRelationMaps(): array
    {
        return $this->relationMaps;
    }

    public function syncContacts(): SyncResult
    {
        $mapping = $this->config->getMapping('contact');
        if ($mapping === null) {
            throw new \RuntimeException('No mapping configured for contacts');
        }

        $since = $this->resolveSince('contact');
        $syncStartTime = new \DateTimeImmutable();
        $offset = $this->offsets['contact'] ?? 0;
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($this->crmAdapter->iterateContacts($since, $offset) as $contact) {
            $record = $this->syncEntityToCc(
                entity: $contact,
                mapping: $mapping,
                entityType: 'contact',
                upsertFn: $this->buildUpsertFn('contact'),
            );

            $result->addRecord($record);
            $count++;

            if ($count >= $this->config->batchSize) {
                $exhausted = false;
                break;
            }
        }

        $result->setExhausted($exhausted);
        $result->finish();

        if ($exhausted) {
            $this->offsets['contact'] = 0;
            $this->saveState('contact', $syncStartTime, $result);
        } else {
            $this->offsets['contact'] = $offset + $count;
        }

        $this->logger->info('Batch contact sync completed', [
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'skipped' => $result->getSkippedCount(),
            'failed' => $result->getFailedCount(),
            'incremental' => $since !== null,
        ]);

        return $result;
    }

    public function syncAccounts(): SyncResult
    {
        $mapping = $this->config->getMapping('account');
        if ($mapping === null) {
            throw new \RuntimeException('No mapping configured for accounts');
        }

        $since = $this->resolveSince('account');
        $syncStartTime = new \DateTimeImmutable();
        $offset = $this->offsets['account'] ?? 0;
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($this->crmAdapter->iterateAccounts($since, $offset) as $account) {
            $record = $this->syncEntityToCc(
                entity: $account,
                mapping: $mapping,
                entityType: 'account',
                upsertFn: $this->buildUpsertFn('account'),
            );

            $result->addRecord($record);
            $count++;

            if ($count >= $this->config->batchSize) {
                $exhausted = false;
                break;
            }
        }

        $result->setExhausted($exhausted);
        $result->finish();

        if ($exhausted) {
            $this->offsets['account'] = 0;
            $this->saveState('account', $syncStartTime, $result);
        } else {
            $this->offsets['account'] = $offset + $count;
        }

        $this->logger->info('Batch account sync completed', [
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'skipped' => $result->getSkippedCount(),
            'failed' => $result->getFailedCount(),
            'incremental' => $since !== null,
        ]);

        return $result;
    }

    /**
     * @param ActivityType[] $activityTypes
     */
    public function syncActivities(array $activityTypes = []): SyncResult
    {
        $mapping = $this->config->getMapping('activity');
        if ($mapping === null) {
            throw new \RuntimeException('No mapping configured for activities');
        }

        if ($activityTypes === []) {
            $entityConfig = $this->config->getEntityConfig('activity');
            $activityTypes = $entityConfig !== null ? $entityConfig->activityTypes : [ActivityType::Call];
        }

        $since = $this->resolveSince('activity');
        $syncStartTime = new \DateTimeImmutable();
        $offset = $this->offsets['activity'] ?? 0;
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($activityTypes as $type) {
            foreach ($this->ccAdapter->iterateActivities($type, $since, $offset) as $activity) {
                $record = $this->syncActivityToCrm($activity, $mapping);
                $result->addRecord($record);
                $count++;

                if ($count >= $this->config->batchSize) {
                    $exhausted = false;
                    break 2;
                }
            }
        }

        $result->setExhausted($exhausted);
        $result->finish();

        if ($exhausted) {
            $this->offsets['activity'] = 0;
            $this->saveState('activity', $syncStartTime, $result);
        } else {
            $this->offsets['activity'] = $offset + $count;
        }

        $this->logger->info('Batch activity sync completed', [
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
            'failed' => $result->getFailedCount(),
            'incremental' => $since !== null,
        ]);

        return $result;
    }

    /**
     * @param callable(string, array<string, mixed>): UpsertResult $upsertFn
     */
    private function syncEntityToCc(
        EntityInterface $entity,
        MappingCollection $mapping,
        string $entityType,
        callable $upsertFn,
    ): RecordResult {
        try {
            $this->ensureMappingRelations($entity, $mapping);

            $mapped = $this->fieldMapper->map($entity, $mapping, SyncDirection::CrmToCc, $this->relationMaps);
            $upsertResult = $upsertFn($mapping->lookupField, $mapped);
            $synced = $upsertResult->entity;

            $wasCreated = !$upsertResult->skipped && ($entity->getId() !== $synced->getId());

            $record = new RecordResult(
                entityType: $entityType,
                sourceId: $entity->getId(),
                targetId: $synced->getId(),
                status: $upsertResult->skipped
                    ? SyncStatus::Skipped
                    : ($wasCreated ? SyncStatus::Created : SyncStatus::Updated),
            );

            if ($record->status !== SyncStatus::Failed && $entity->getId() !== null && $record->targetId !== null) {
                $this->relationMaps[$entityType] ??= [];
                $this->relationMaps[$entityType][(string) $entity->getId()] = $record->targetId;
            }

            return $record;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync {type} {id}: {error}', [
                'type' => $entityType,
                'id' => $entity->getId(),
                'error' => $e->getMessage(),
            ]);

            return new RecordResult(
                entityType: $entityType,
                sourceId: $entity->getId(),
                targetId: null,
                status: SyncStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function syncActivityToCrm(Activity $activity, MappingCollection $mapping): RecordResult
    {
        try {
            $mapped = $this->fieldMapper->map($activity, $mapping, SyncDirection::CcToCrm, $this->relationMaps);
            $mappedActivity = Activity::fromArray($mapped);

            if ($activity->getActivityType() !== null) {
                $mappedActivity->setActivityType($activity->getActivityType());
            }

            $result = $this->crmAdapter->upsertActivity($mapping->lookupField, $mappedActivity);

            $wasCreated = $activity->getId() !== $result->getId();

            return new RecordResult(
                entityType: 'activity',
                sourceId: $activity->getId(),
                targetId: $result->getId(),
                status: $wasCreated ? SyncStatus::Created : SyncStatus::Updated,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync activity {id}: {error}', [
                'id' => $activity->getId(),
                'error' => $e->getMessage(),
            ]);

            return new RecordResult(
                entityType: 'activity',
                sourceId: $activity->getId(),
                targetId: null,
                status: SyncStatus::Failed,
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * @return (callable(string, array<string, mixed>): UpsertResult)|null
     */
    private function buildUpsertFn(string $entityType): ?callable
    {
        return match ($entityType) {
            'account' => fn (string $lookupField, array $data) => $this->ccAdapter->upsertAccount(
                $lookupField,
                \Daktela\CrmSync\Entity\Account::fromArray($data),
            ),
            'contact' => fn (string $lookupField, array $data) => $this->ccAdapter->upsertContact(
                $lookupField,
                \Daktela\CrmSync\Entity\Contact::fromArray($data),
            ),
            default => null,
        };
    }

    private function findCrmEntity(string $entityType, string $id): ?EntityInterface
    {
        return match ($entityType) {
            'account' => $this->crmAdapter->findAccount($id),
            'contact' => $this->crmAdapter->findContact($id),
            default => null,
        };
    }

    private function ensureCrmEntityInCc(string $entityType, string $crmId): ?RecordResult
    {
        if (isset($this->relationMaps[$entityType][$crmId])) {
            return null;
        }

        $guardKey = $entityType . ':' . $crmId;
        if (isset($this->syncingEntities[$guardKey])) {
            return null;
        }

        $this->syncingEntities[$guardKey] = true;

        try {
            $entity = $this->findCrmEntity($entityType, $crmId);
            if ($entity === null) {
                $this->logger->warning('Cannot auto-create {type} {id}: not found in CRM', [
                    'type' => $entityType,
                    'id' => $crmId,
                ]);
                return null;
            }

            $mapping = $this->config->getMapping($entityType);
            if ($mapping === null) {
                return null;
            }

            $upsertFn = $this->buildUpsertFn($entityType);
            if ($upsertFn === null) {
                return null;
            }

            return $this->syncEntityToCc(
                entity: $entity,
                mapping: $mapping,
                entityType: $entityType,
                upsertFn: $upsertFn,
            );
        } finally {
            unset($this->syncingEntities[$guardKey]);
        }
    }

    private function ensureMappingRelations(EntityInterface $entity, MappingCollection $mapping): void
    {
        foreach ($mapping->mappings as $fieldMapping) {
            if ($fieldMapping->relation === null) {
                continue;
            }

            $value = $this->fieldMapper->readNestedValue($entity, $fieldMapping->crmField);
            if ($value === null || $value === '') {
                continue;
            }

            if (!isset($this->relationMaps[$fieldMapping->relation->entity][(string) $value])) {
                $this->ensureCrmEntityInCc($fieldMapping->relation->entity, (string) $value);
            }
        }
    }

    /**
     * Builds a relation map by iterating CRM entities and mapping their IDs
     * to the corresponding Daktela field values.
     */
    private function buildRelationMap(RelationConfig $relation): void
    {
        if (isset($this->relationMaps[$relation->entity])) {
            return; // Already built
        }

        $this->relationMaps[$relation->entity] = [];

        $mapping = $this->config->getMapping($relation->entity);
        if ($mapping === null) {
            return;
        }

        // Find what CC field maps to resolve_to by scanning the entity's own mappings
        $resolveToSourceField = null;
        foreach ($mapping->mappings as $fm) {
            if ($fm->ccField === $relation->resolveTo) {
                $resolveToSourceField = $fm->crmField; // CRM-side field
                break;
            }
        }

        if ($resolveToSourceField === null) {
            $this->logger->warning('Cannot build relation map for {entity}: resolve_to field "{field}" not found in mappings', [
                'entity' => $relation->entity,
                'field' => $relation->resolveTo,
            ]);
            return;
        }

        $iterator = match ($relation->entity) {
            'account' => $this->crmAdapter->iterateAccounts(),
            'contact' => $this->crmAdapter->iterateContacts(),
            default => null,
        };

        if ($iterator === null) {
            return;
        }

        foreach ($iterator as $entity) {
            $fromValue = $entity->get($relation->resolveFrom);
            $toValue = $entity->get($resolveToSourceField);

            if ($fromValue !== null && $toValue !== null) {
                $this->relationMaps[$relation->entity][(string) $fromValue] = (string) $toValue;
            }
        }

        $this->logger->info('Built relation map for {entity}: {count} entries', [
            'entity' => $relation->entity,
            'count' => count($this->relationMaps[$relation->entity]),
        ]);
    }

    private function resolveSince(string $entityType): ?\DateTimeImmutable
    {
        if ($this->stateStore === null || $this->forceFullSync) {
            return null;
        }

        return $this->stateStore->getLastSyncTime($entityType);
    }

    private function saveState(string $entityType, \DateTimeImmutable $syncStartTime, SyncResult $result): void
    {
        if ($this->stateStore === null) {
            return;
        }

        if ($result->getFailedCount() > 0) {
            $this->logger->warning('State not saved for {entityType}: {failedCount} failed records', [
                'entityType' => $entityType,
                'failedCount' => $result->getFailedCount(),
            ]);

            return;
        }

        $this->stateStore->setLastSyncTime($entityType, $syncStartTime);
    }

}
