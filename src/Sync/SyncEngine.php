<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\State\SyncStateStoreInterface;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Psr\Log\LoggerInterface;

final class SyncEngine
{
    private readonly BatchSync $batchSync;

    private readonly WebhookSync $webhookSync;

    public function __construct(
        private readonly ContactCentreAdapterInterface $ccAdapter,
        private readonly CrmAdapterInterface $crmAdapter,
        private readonly SyncConfiguration $config,
        private readonly LoggerInterface $logger,
        ?TransformerRegistry $transformerRegistry = null,
        private readonly ?SyncStateStoreInterface $stateStore = null,
    ) {
        $registry = $transformerRegistry ?? TransformerRegistry::withDefaults();
        $fieldMapper = new FieldMapper($registry);

        $this->batchSync = new BatchSync(
            $this->ccAdapter,
            $this->crmAdapter,
            $fieldMapper,
            $this->config,
            $this->logger,
            $this->stateStore,
        );

        $this->webhookSync = new WebhookSync(
            $this->ccAdapter,
            $this->crmAdapter,
            $fieldMapper,
            $this->config,
            $this->logger,
        );
    }

    /**
     * Full sync in the correct dependency order:
     * 1. Accounts (CRM → Daktela) — builds relation maps for contact→account references
     * 2. Contacts (CRM → Daktela) — uses relation maps to resolve account references
     * 3. Activities (Daktela → CRM)
     *
     * @param ActivityType[] $activityTypes
     * @return array<string, SyncResult> Keyed by entity type: 'account', 'contact', 'activity'
     */
    public function fullSync(array $activityTypes = [], bool $forceFullSync = false): array
    {
        $this->batchSync->setForceFullSync($forceFullSync);
        $results = [];

        // Step 1: Sync accounts first (builds relation maps)
        if ($this->config->isEntityEnabled('account')) {
            $this->logger->info('Full sync: starting accounts');
            $this->batchSync->resetOffsets();
            $accountResult = new SyncResult();
            do {
                $batch = $this->batchSync->syncAccounts();
                $accountResult->merge($batch);
            } while (!$batch->isExhausted());
            $accountResult->finish();
            $results['account'] = $accountResult;
        }

        // Step 2: Build relation maps from contact mapping configs
        // Only if accounts weren't synced above (syncAccounts builds relation maps directly)
        if (!$this->config->isEntityEnabled('account')) {
            $this->batchSync->buildRelationMaps();
        }

        // Step 3: Sync contacts (uses relation maps to resolve account references)
        if ($this->config->isEntityEnabled('contact')) {
            $this->logger->info('Full sync: starting contacts');
            $this->batchSync->resetOffsets();
            $contactResult = new SyncResult();
            do {
                $batch = $this->batchSync->syncContacts();
                $contactResult->merge($batch);
            } while (!$batch->isExhausted());
            $contactResult->finish();
            $results['contact'] = $contactResult;
        }

        // Step 4: Sync activities
        if ($this->config->isEntityEnabled('activity')) {
            $this->logger->info('Full sync: starting activities');
            $this->batchSync->resetOffsets();
            $activityResult = new SyncResult();
            do {
                $batch = $this->batchSync->syncActivities($activityTypes);
                $activityResult->merge($batch);
            } while (!$batch->isExhausted());
            $activityResult->finish();
            $results['activity'] = $activityResult;
        }

        $this->batchSync->setForceFullSync(false);

        return $results;
    }

    public function resetState(?string $entityType = null): void
    {
        if ($this->stateStore === null) {
            return;
        }

        if ($entityType !== null) {
            $this->stateStore->clear($entityType);
        } else {
            $this->stateStore->clearAll();
        }
    }

    public function syncContactsBatch(): SyncResult
    {
        return $this->batchSync->syncContacts();
    }

    public function syncAccountsBatch(): SyncResult
    {
        return $this->batchSync->syncAccounts();
    }

    /**
     * @param ActivityType[] $activityTypes
     */
    public function syncActivitiesBatch(array $activityTypes = []): SyncResult
    {
        return $this->batchSync->syncActivities($activityTypes);
    }

    public function syncContact(string $id): SyncResult
    {
        return $this->webhookSync->syncContact($id);
    }

    public function syncAccount(string $id): SyncResult
    {
        return $this->webhookSync->syncAccount($id);
    }

    public function syncActivity(string $id, ActivityType $type): SyncResult
    {
        return $this->webhookSync->syncActivity($id, $type);
    }
}
