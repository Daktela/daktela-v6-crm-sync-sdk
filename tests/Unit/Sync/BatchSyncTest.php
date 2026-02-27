<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\State\SyncStateStoreInterface;
use Daktela\CrmSync\Sync\BatchSync;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BatchSyncTest extends TestCase
{
    public function testBatchSizeLimitsProcessedRecords(): void
    {
        $contacts = [];
        for ($i = 0; $i < 10; $i++) {
            $contacts[] = Contact::fromArray([
                'id' => "crm-{$i}",
                'full_name' => "Contact {$i}",
                'email' => "contact{$i}@test.com",
            ]);
        }

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->gen($contacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-new']),
            ));

        $config = $this->createConfig(batchSize: 5);
        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
        );

        $result = $batchSync->syncContacts();

        self::assertSame(5, $result->getTotalCount());
    }

    public function testSyncActivitiesUsesConfiguredTypes(): void
    {
        $activities = [
            Activity::fromArray(['id' => 'call-1', 'activity_type' => 'call', 'name' => 'c1', 'title' => 'Call 1']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $ccAdapter->expects(self::once())
            ->method('iterateActivities')
            ->with(ActivityType::Call)
            ->willReturn($this->gen($activities));

        $crmAdapter->method('upsertActivity')
            ->willReturn(Activity::fromArray(['id' => 'crm-act-1']));

        $config = $this->createConfig();
        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $config,
            new NullLogger(),
        );

        $result = $batchSync->syncActivities([ActivityType::Call]);

        self::assertSame(1, $result->getTotalCount());
    }

    public function testFailedRecordsAreCaptured(): void
    {
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->gen($contacts));
        $ccAdapter->method('upsertContact')->willThrowException(new \RuntimeException('API failure'));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $batchSync->syncContacts();

        self::assertSame(1, $result->getFailedCount());
        self::assertSame(SyncStatus::Failed, $result->getRecords()[0]->status);
    }

    public function testBuildRelationMapsPopulatesMap(): void
    {
        $crmAccounts = [
            Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
            Account::fromArray(['id' => 'acc-2', 'company_name' => 'Globex', 'external_id' => 'globex']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateAccounts')->willReturn($this->gen($crmAccounts));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfigWithRelations(),
            new NullLogger(),
        );

        $batchSync->buildRelationMaps();

        $maps = $batchSync->getRelationMaps();
        self::assertArrayHasKey('account', $maps);
        self::assertSame('acme', $maps['account']['acc-1']);
        self::assertSame('globex', $maps['account']['acc-2']);
    }

    public function testSyncAccountsAddsToRelationMap(): void
    {
        $crmAccounts = [
            Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateAccounts')->willReturn($this->gen($crmAccounts));

        $ccAdapter->method('upsertAccount')
            ->willReturnCallback(fn ($lookup, $account) => Account::fromArray(
                array_merge($account->toArray(), ['id' => 'cc-acc-1']),
            ));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfigWithRelations(),
            new NullLogger(),
        );

        $batchSync->syncAccounts();

        $maps = $batchSync->getRelationMaps();
        self::assertArrayHasKey('account', $maps);
        self::assertSame('cc-acc-1', $maps['account']['acc-1']);
    }

    public function testIncrementalSyncPassesSinceToAdapter(): void
    {
        $since = new \DateTimeImmutable('2025-06-15T10:00:00+00:00');
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->with('contact')->willReturn($since);
        $stateStore->method('setLastSyncTime');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->expects(self::once())
            ->method('iterateContacts')
            ->with($since)
            ->willReturn($this->gen($contacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-1']),
            ));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
            $stateStore,
        );

        $batchSync->syncContacts();
    }

    public function testNoStateStorePassesNullForFullSync(): void
    {
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->expects(self::once())
            ->method('iterateContacts')
            ->with(null)
            ->willReturn($this->gen($contacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-1']),
            ));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $batchSync->syncContacts();
    }

    public function testForceFullSyncIgnoresStateStore(): void
    {
        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->expects(self::never())->method('getLastSyncTime');

        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->expects(self::once())
            ->method('iterateContacts')
            ->with(null)
            ->willReturn($this->gen($contacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-1']),
            ));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
            $stateStore,
        );

        $batchSync->setForceFullSync(true);
        $batchSync->syncContacts();
    }

    public function testSavesTimestampAfterSuccessfulSync(): void
    {
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);
        $stateStore->expects(self::once())
            ->method('setLastSyncTime')
            ->with('contact', self::isInstanceOf(\DateTimeImmutable::class));

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->gen($contacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-1']),
            ));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
            $stateStore,
        );

        $batchSync->syncContacts();
    }

    public function testDoesNotSaveTimestampWhenRecordsFail(): void
    {
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);
        $stateStore->expects(self::never())->method('setLastSyncTime');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->gen($contacts));
        $ccAdapter->method('upsertContact')->willThrowException(new \RuntimeException('API failure'));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
            $stateStore,
        );

        $batchSync->syncContacts();
    }

    public function testDoesNotSaveTimestampWhenBatchSizeLimitReached(): void
    {
        $contacts = [];
        for ($i = 0; $i < 10; $i++) {
            $contacts[] = Contact::fromArray([
                'id' => "crm-{$i}",
                'full_name' => "Contact {$i}",
                'email' => "contact{$i}@test.com",
            ]);
        }

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->willReturn(null);
        $stateStore->expects(self::never())->method('setLastSyncTime');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->gen($contacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-new']),
            ));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(batchSize: 5),
            new NullLogger(),
            $stateStore,
        );

        $batchSync->syncContacts();
    }

    public function testFirstRunWithStateStorePassesNull(): void
    {
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $stateStore = $this->createMock(SyncStateStoreInterface::class);
        $stateStore->method('getLastSyncTime')->with('contact')->willReturn(null);
        $stateStore->method('setLastSyncTime');

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->expects(self::once())
            ->method('iterateContacts')
            ->with(null)
            ->willReturn($this->gen($contacts));

        $ccAdapter->method('upsertContact')
            ->willReturnCallback(fn ($lookup, $contact) => Contact::fromArray(
                array_merge($contact->toArray(), ['id' => 'cc-1']),
            ));

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
            $stateStore,
        );

        $batchSync->syncContacts();
    }

    public function testSkippedContactIsTrackedAsSyncStatusSkipped(): void
    {
        $contacts = [
            Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateContacts')->willReturn($this->gen($contacts));

        // Simulate adapter returning an entity with _syncSkipped flag (no changes detected)
        $ccAdapter->method('upsertContact')
            ->willReturnCallback(function ($lookup, $contact) {
                $existing = Contact::fromArray(array_merge($contact->toArray(), ['id' => 'cc-1']));
                $existing->set('_syncSkipped', true);

                return $existing;
            });

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfig(),
            new NullLogger(),
        );

        $result = $batchSync->syncContacts();

        self::assertSame(1, $result->getTotalCount());
        self::assertSame(1, $result->getSkippedCount());
        self::assertSame(0, $result->getCreatedCount());
        self::assertSame(0, $result->getUpdatedCount());
        self::assertSame(SyncStatus::Skipped, $result->getRecords()[0]->status);
    }

    public function testSkippedAccountStillPopulatesRelationMap(): void
    {
        $crmAccounts = [
            Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
        ];

        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('iterateAccounts')->willReturn($this->gen($crmAccounts));

        // Simulate adapter returning skipped entity
        $ccAdapter->method('upsertAccount')
            ->willReturnCallback(function ($lookup, $account) {
                $existing = Account::fromArray(array_merge($account->toArray(), ['id' => 'cc-acc-1']));
                $existing->set('_syncSkipped', true);

                return $existing;
            });

        $batchSync = new BatchSync(
            $ccAdapter,
            $crmAdapter,
            new FieldMapper(TransformerRegistry::withDefaults()),
            $this->createConfigWithRelations(),
            new NullLogger(),
        );

        $result = $batchSync->syncAccounts();

        self::assertSame(1, $result->getSkippedCount());
        self::assertSame(0, $result->getUpdatedCount());

        // Relation map should still be populated for skipped records
        $maps = $batchSync->getRelationMaps();
        self::assertArrayHasKey('account', $maps);
        self::assertSame('cc-acc-1', $maps['account']['acc-1']);
    }

    private function createConfig(int $batchSize = 100): SyncConfiguration
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
            batchSize: $batchSize,
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

    private function createConfigWithRelations(): SyncConfiguration
    {
        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name'),
            new FieldMapping('email', 'email'),
            new FieldMapping(
                ccField: 'account',
                crmField: 'company_id',
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);

        $accountMapping = new MappingCollection('account', 'name', [
            new FieldMapping('title', 'company_name'),
            new FieldMapping('name', 'external_id'),
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
                'account' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'accounts.yaml'),
                'activity' => new EntitySyncConfig(true, SyncDirection::CcToCrm, 'activities.yaml', [ActivityType::Call]),
            ],
            mappings: [
                'contact' => $contactMapping,
                'account' => $accountMapping,
                'activity' => $activityMapping,
            ],
        );
    }

    /**
     * @template T
     * @param T[] $items
     * @return \Generator<int, T>
     */
    private function gen(array $items): \Generator
    {
        yield from $items;
    }
}
