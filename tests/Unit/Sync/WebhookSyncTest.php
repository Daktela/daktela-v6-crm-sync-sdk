<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Adapter\UpsertResult;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\WebhookSync;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class WebhookSyncTest extends TestCase
{
    public function testSyncContactSuccess(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')
            ->with('crm-1')
            ->willReturn(Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']));

        $ccAdapter->method('upsertContact')
            ->willReturn(new UpsertResult(Contact::fromArray(['id' => 'cc-1'])));

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncContact('crm-1');

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status);
    }

    public function testSyncContactNotFound(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')->willReturn(null);

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncContact('nonexistent');

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(SyncStatus::Skipped, $result->getRecords()[0]->status);
    }

    public function testSyncActivitySuccess(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $activity = Activity::fromArray([
            'id' => 'call-1',
            'activity_type' => 'call',
            'name' => 'call-1',
            'title' => 'Test call',
        ]);

        $ccAdapter->method('findActivity')
            ->with('call-1', ActivityType::Call)
            ->willReturn($activity);

        $crmAdapter->method('upsertActivity')
            ->willReturn(Activity::fromArray(['id' => 'crm-act-1']));

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncActivity('call-1', ActivityType::Call);

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(SyncStatus::Updated, $result->getRecords()[0]->status);
    }

    public function testSyncContactHandlesException(): void
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')->willThrowException(new \RuntimeException('CRM error'));

        $webhookSync = new WebhookSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $webhookSync->syncContact('crm-1');

        self::assertSame(1, $result->getFailedCount());
        self::assertSame('CRM error', $result->getFailedRecords()[0]->errorMessage);
    }

    private function createConfig(): SyncConfiguration
    {
        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
        ]);

        $activityMapping = new MappingCollection('activity', 'name', [
            new FieldMapping('name', 'external_id'),
            new FieldMapping('title', 'subject'),
        ]);

        return new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: [
                'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call]),
            ],
            mappings: [
                'contact' => $contactMapping,
                'activity' => $activityMapping,
            ],
        );
    }
}
