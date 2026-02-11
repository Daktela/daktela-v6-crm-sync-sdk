<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Sync\Result\RecordResult;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use Psr\Log\LoggerInterface;

final class WebhookSync
{
    public function __construct(
        private readonly ContactCentreAdapterInterface $ccAdapter,
        private readonly CrmAdapterInterface $crmAdapter,
        private readonly FieldMapper $fieldMapper,
        private readonly SyncConfiguration $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function syncContact(string $id): SyncResult
    {
        $result = new SyncResult();
        $mapping = $this->config->getMapping('contact');

        if ($mapping === null) {
            $result->finish();
            return $result;
        }

        try {
            $contact = $this->crmAdapter->findContact($id);
            if ($contact === null) {
                $result->addRecord(new RecordResult('contact', $id, null, SyncStatus::Skipped));
                $result->finish();
                return $result;
            }

            $mapped = $this->fieldMapper->map($contact, $mapping, SyncDirection::CrmToCc);
            $synced = $this->ccAdapter->upsertContact($mapping->lookupField, Contact::fromArray($mapped));

            $result->addRecord(new RecordResult('contact', $id, $synced->getId(), SyncStatus::Updated));
        } catch (\Throwable $e) {
            $this->logger->error('Webhook sync failed for contact {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $result->addRecord(new RecordResult('contact', $id, null, SyncStatus::Failed, $e->getMessage()));
        }

        $result->finish();
        return $result;
    }

    public function syncAccount(string $id): SyncResult
    {
        $result = new SyncResult();
        $mapping = $this->config->getMapping('account');

        if ($mapping === null) {
            $result->finish();
            return $result;
        }

        try {
            $account = $this->crmAdapter->findAccount($id);
            if ($account === null) {
                $result->addRecord(new RecordResult('account', $id, null, SyncStatus::Skipped));
                $result->finish();
                return $result;
            }

            $mapped = $this->fieldMapper->map($account, $mapping, SyncDirection::CrmToCc);
            $synced = $this->ccAdapter->upsertAccount($mapping->lookupField, Account::fromArray($mapped));

            $result->addRecord(new RecordResult('account', $id, $synced->getId(), SyncStatus::Updated));
        } catch (\Throwable $e) {
            $this->logger->error('Webhook sync failed for account {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $result->addRecord(new RecordResult('account', $id, null, SyncStatus::Failed, $e->getMessage()));
        }

        $result->finish();
        return $result;
    }

    public function syncActivity(string $id, ActivityType $type): SyncResult
    {
        $result = new SyncResult();
        $mapping = $this->config->getMapping('activity');

        if ($mapping === null) {
            $result->finish();
            return $result;
        }

        try {
            $activity = $this->ccAdapter->findActivity($id, $type);
            if ($activity === null) {
                $result->addRecord(new RecordResult('activity', $id, null, SyncStatus::Skipped));
                $result->finish();
                return $result;
            }

            $mapped = $this->fieldMapper->map($activity, $mapping, SyncDirection::CcToCrm);
            $mappedActivity = Activity::fromArray($mapped);

            if ($activity->getActivityType() !== null) {
                $mappedActivity->setActivityType($activity->getActivityType());
            }

            $synced = $this->crmAdapter->upsertActivity($mapping->lookupField, $mappedActivity);

            $result->addRecord(new RecordResult('activity', $id, $synced->getId(), SyncStatus::Updated));
        } catch (\Throwable $e) {
            $this->logger->error('Webhook sync failed for activity {id}: {error}', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);
            $result->addRecord(new RecordResult('activity', $id, null, SyncStatus::Failed, $e->getMessage()));
        }

        $result->finish();
        return $result;
    }
}
