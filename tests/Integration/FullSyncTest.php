<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Integration;

use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\SyncEngine;
use Daktela\CrmSync\Tests\Integration\Fakes\FakeCcAdapter;
use Daktela\CrmSync\Tests\Integration\Fakes\FakeCrmAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Wires SyncEngine + real FieldMapper + real FileSyncStateStore against
 * in-memory fake adapters to exercise the pieces that unit tests cover
 * individually: state persistence, multi-entity ordering, relation maps,
 * and the partial-failure state-saving policy.
 */
final class FullSyncTest extends TestCase
{
    private string $stateFile;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/crm-sync-state-' . uniqid('', true) . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->stateFile)) {
            unlink($this->stateFile);
        }
    }

    public function testFullSyncPersistsStateForAccountsAndContacts(): void
    {
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com', 'company_id' => 'acc-1']),
            ],
            accounts: [
                Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
            ],
        );
        $cc = new FakeCcAdapter();
        $engine = $this->engine($crm, $cc);

        $engine->fullSync();

        self::assertFileExists($this->stateFile);
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertIsArray($state);
        self::assertArrayHasKey('account', $state);
        self::assertArrayHasKey('contact', $state);
        // Round-tripped by the real FileSyncStateStore
        self::assertNotNull(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $state['account']));
        self::assertNotNull(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $state['contact']));

        // Both entities reached the CC side through the real FieldMapper
        self::assertCount(1, $cc->accounts);
        self::assertCount(1, $cc->contacts);
        $contact = array_values($cc->contacts)[0];
        // Relation was resolved end-to-end: CRM 'company_id' → CC account ID
        self::assertSame('cc-account-1', $contact->get('account'));
    }

    public function testPartialFailureStillSavesState(): void
    {
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com']),
                Contact::fromArray(['id' => 'crm-c-2', 'full_name' => 'Bob', 'email' => 'bob@acme.com']),
            ],
        );
        $cc = new FakeCcAdapter();
        $cc->failContactOn('bob@acme.com');

        $engine = $this->engine($crm, $cc, accountEnabled: false);

        $result = $engine->syncContactsBatch();

        self::assertSame(1, $result->getFailedCount());
        self::assertSame(1, $result->getCreatedCount());
        // Fix from commit 7ab3eeb parent: state persists despite partial failure,
        // otherwise the failed record would be retried forever on every run.
        self::assertFileExists($this->stateFile);
        $state = json_decode((string) file_get_contents($this->stateFile), true);
        self::assertArrayHasKey('contact', $state);
    }

    public function testAllFailedDoesNotSaveState(): void
    {
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com']),
                Contact::fromArray(['id' => 'crm-c-2', 'full_name' => 'Bob', 'email' => 'bob@acme.com']),
            ],
        );
        $cc = new FakeCcAdapter();
        $cc->failContactOn('alice@acme.com');
        $cc->failContactOn('bob@acme.com');

        $engine = $this->engine($crm, $cc, accountEnabled: false);

        $result = $engine->syncContactsBatch();

        self::assertSame(2, $result->getFailedCount());
        self::assertSame(0, $result->getCreatedCount());
        // When every record fails, saving state would silently skip them next run.
        // The state file may exist (written by setUp-adjacent code) but should not
        // contain a 'contact' entry.
        if (file_exists($this->stateFile)) {
            $state = json_decode((string) file_get_contents($this->stateFile), true);
            self::assertArrayNotHasKey('contact', is_array($state) ? $state : []);
        }
    }

    public function testSecondRunReadsStateAndFiltersBySince(): void
    {
        $crm = new FakeCrmAdapter(
            contacts: [
                Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com']),
            ],
        );
        $cc = new FakeCcAdapter();
        $engine = $this->engine($crm, $cc, accountEnabled: false);

        // First run: no prior state → iterator called with since=null
        $engine->syncContactsBatch();

        $firstRunCalls = array_values(array_filter(
            $crm->iterateCalls,
            fn (array $c) => $c['type'] === 'contact',
        ));
        self::assertNotEmpty($firstRunCalls);
        self::assertNull($firstRunCalls[0]['since']);

        // Second run: state file was persisted on disk → iterator receives since!=null
        $engine2 = $this->engine($crm, $cc, accountEnabled: false);
        $engine2->syncContactsBatch();

        $allCalls = array_values(array_filter(
            $crm->iterateCalls,
            fn (array $c) => $c['type'] === 'contact',
        ));
        self::assertGreaterThan(1, count($allCalls));
        $secondCallSince = end($allCalls)['since'];
        self::assertNotNull($secondCallSince);
    }

    private function engine(
        FakeCrmAdapter $crm,
        FakeCcAdapter $cc,
        bool $accountEnabled = true,
    ): SyncEngine {
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

        $entities = [
            'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
        ];
        if ($accountEnabled) {
            $entities['account'] = new EntitySyncConfig(true, SyncDirection::CrmToCc, 'accounts.yaml');
        }

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            database: 'test-db',
            batchSize: 100,
            entities: $entities,
            mappings: [
                'contact' => $contactMapping,
                'account' => $accountMapping,
            ],
        );

        return new SyncEngine(
            ccAdapter: $cc,
            crmAdapter: $crm,
            config: $config,
            logger: new NullLogger(),
            stateStore: new FileSyncStateStore($this->stateFile),
        );
    }
}
