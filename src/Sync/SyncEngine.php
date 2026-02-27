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
use Daktela\CrmSync\Sync\Result\AccountSyncResult;
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
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     * @return array<string, SyncResult> Keyed by entity type: 'account', 'auto_contact', 'contact', 'activity'
     */
    public function fullSync(
        array $activityTypes = [],
        bool $forceFullSync = false,
        ?callable $onBatch = null,
    ): array {
        $this->batchSync->setForceFullSync($forceFullSync);
        $results = [];

        // Step 1: Sync accounts first (builds relation maps)
        if ($this->config->isEntityEnabled('account')) {
            $this->logger->info('Full sync: starting accounts');
            $this->batchSync->resetOffsets();
            $accountResult = new SyncResult();
            $autoContactResult = new SyncResult();
            do {
                $batch = $this->batchSync->syncAccounts();
                if ($onBatch !== null) {
                    $onBatch('account', $batch->account);
                    if ($batch->autoContact->getTotalCount() > 0) {
                        $onBatch('auto_contact', $batch->autoContact);
                    }
                }
                $accountResult->mergeCounts($batch->account);
                $autoContactResult->mergeCounts($batch->autoContact);
            } while (!$batch->account->isExhausted());
            $accountResult->finish();
            $autoContactResult->finish();
            $results['account'] = $accountResult;
            $results['auto_contact'] = $autoContactResult;
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
                if ($onBatch !== null) {
                    $onBatch('contact', $batch);
                }
                $contactResult->mergeCounts($batch);
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
                if ($onBatch !== null) {
                    $onBatch('activity', $batch);
                }
                $activityResult->mergeCounts($batch);
            } while (!$batch->isExhausted());
            $activityResult->finish();
            $results['activity'] = $activityResult;
        }

        $this->batchSync->setForceFullSync(false);

        return $results;
    }

    /**
     * @throws \RuntimeException if either connection fails
     */
    public function testConnections(): void
    {
        if (!$this->crmAdapter->ping()) {
            throw new \RuntimeException('Cannot connect to CRM API');
        }
        $this->logger->info('CRM connection OK');

        if (!$this->ccAdapter->ping()) {
            throw new \RuntimeException('Cannot connect to Daktela API');
        }
        $this->logger->info('Daktela connection OK');
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

    /**
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function syncContactsBatch(?callable $onBatch = null): SyncResult
    {
        $result = new SyncResult();
        do {
            $batch = $this->batchSync->syncContacts();
            if ($onBatch !== null) {
                $onBatch('contact', $batch);
            }
            $result->mergeCounts($batch);
        } while (!$batch->isExhausted());
        $result->finish();

        return $result;
    }

    /**
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function syncAccountsBatch(?callable $onBatch = null): AccountSyncResult
    {
        $accountResult = new SyncResult();
        $autoContactResult = new SyncResult();
        do {
            $batch = $this->batchSync->syncAccounts();
            if ($onBatch !== null) {
                $onBatch('account', $batch->account);
                if ($batch->autoContact->getTotalCount() > 0) {
                    $onBatch('auto_contact', $batch->autoContact);
                }
            }
            $accountResult->mergeCounts($batch->account);
            $autoContactResult->mergeCounts($batch->autoContact);
        } while (!$batch->account->isExhausted());
        $accountResult->finish();
        $autoContactResult->finish();

        return new AccountSyncResult($accountResult, $autoContactResult);
    }

    /**
     * @param ActivityType[] $activityTypes
     * @param ?callable(string, SyncResult): void $onBatch Called after each batch with entity type and batch result
     */
    public function syncActivitiesBatch(array $activityTypes = [], ?callable $onBatch = null): SyncResult
    {
        $result = new SyncResult();
        do {
            $batch = $this->batchSync->syncActivities($activityTypes);
            if ($onBatch !== null) {
                $onBatch('activity', $batch);
            }
            $result->mergeCounts($batch);
        } while (!$batch->isExhausted());
        $result->finish();

        return $result;
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
