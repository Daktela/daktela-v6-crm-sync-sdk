<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
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

    /**
     * Builds relation resolution maps by scanning mapping configs for relation definitions,
     * then iterating the relevant source entities to build CRM-ID → CC-ID maps.
     *
     * Call this before syncContacts() when contacts have account relations.
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
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($this->crmAdapter->iterateContacts($since) as $contact) {
            $record = $this->syncEntityToCc(
                entity: $contact,
                mapping: $mapping,
                entityType: 'contact',
                upsertFn: fn (string $lookupField, array $data) => $this->ccAdapter->upsertContact(
                    $lookupField,
                    \Daktela\CrmSync\Entity\Contact::fromArray($data),
                ),
            );

            $result->addRecord($record);
            $count++;

            if ($count >= $this->config->batchSize) {
                $exhausted = false;
                break;
            }
        }

        $result->finish();

        if ($exhausted) {
            $this->saveState('contact', $syncStartTime, $result);
        }

        $this->logger->info('Batch contact sync completed', [
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
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
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($this->crmAdapter->iterateAccounts($since) as $account) {
            $record = $this->syncEntityToCc(
                entity: $account,
                mapping: $mapping,
                entityType: 'account',
                upsertFn: fn (string $lookupField, array $data) => $this->ccAdapter->upsertAccount(
                    $lookupField,
                    \Daktela\CrmSync\Entity\Account::fromArray($data),
                ),
            );

            // After successful account sync, add to relation map for later contact sync
            if ($record->status !== SyncStatus::Failed && $account->getId() !== null && $record->targetId !== null) {
                $this->relationMaps['account'] ??= [];
                $this->relationMaps['account'][(string) $account->getId()] = $record->targetId;
            }

            $result->addRecord($record);
            $count++;

            if ($count >= $this->config->batchSize) {
                $exhausted = false;
                break;
            }
        }

        $result->finish();

        if ($exhausted) {
            $this->saveState('account', $syncStartTime, $result);
        }

        $this->logger->info('Batch account sync completed', [
            'total' => $result->getTotalCount(),
            'created' => $result->getCreatedCount(),
            'updated' => $result->getUpdatedCount(),
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
        $result = new SyncResult();
        $count = 0;
        $exhausted = true;

        foreach ($activityTypes as $type) {
            foreach ($this->ccAdapter->iterateActivities($type, $since) as $activity) {
                $record = $this->syncActivityToCrm($activity, $mapping);
                $result->addRecord($record);
                $count++;

                if ($count >= $this->config->batchSize) {
                    $exhausted = false;
                    break 2;
                }
            }
        }

        $result->finish();

        if ($exhausted) {
            $this->saveState('activity', $syncStartTime, $result);
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
     * @param callable(string, array<string, mixed>): EntityInterface $upsertFn
     */
    private function syncEntityToCc(
        EntityInterface $entity,
        MappingCollection $mapping,
        string $entityType,
        callable $upsertFn,
    ): RecordResult {
        try {
            $mapped = $this->fieldMapper->map($entity, $mapping, SyncDirection::CrmToCc, $this->relationMaps);
            $result = $upsertFn($mapping->lookupField, $mapped);

            $wasCreated = $entity->getId() !== $result->getId();

            return new RecordResult(
                entityType: $entityType,
                sourceId: $entity->getId(),
                targetId: $result->getId(),
                status: $wasCreated ? SyncStatus::Created : SyncStatus::Updated,
            );
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
            if ($fm->source === $relation->resolveTo) {
                $resolveToSourceField = $fm->target; // CRM-side field
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

    /**
     * After syncing an entity, add its CRM ID → CC lookup value to the relation map
     * so subsequent entity syncs can resolve references.
     */
    private function addToRelationMap(
        string $entityType,
        EntityInterface $entity,
        MappingCollection $mapping,
    ): void {
        // Find all relation configs in other entity mappings that reference this entity type
        $contactMapping = $this->config->getMapping('contact');
        if ($contactMapping === null) {
            return;
        }

        foreach ($contactMapping->mappings as $fm) {
            if ($fm->relation === null || $fm->relation->entity !== $entityType) {
                continue;
            }

            $fromValue = $entity->get($fm->relation->resolveFrom);

            // Find the CRM-side field that maps to resolve_to
            $toValue = null;
            foreach ($mapping->mappings as $entityFm) {
                if ($entityFm->source === $fm->relation->resolveTo) {
                    $toValue = $entity->get($entityFm->target);
                    break;
                }
            }

            if ($fromValue !== null && $toValue !== null) {
                $this->relationMaps[$entityType] ??= [];
                $this->relationMaps[$entityType][(string) $fromValue] = (string) $toValue;
            }
        }
    }
}
