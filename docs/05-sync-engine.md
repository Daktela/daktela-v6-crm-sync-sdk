# Sync Engine

The `SyncEngine` orchestrates syncing between adapters using field mappings.

## Full Sync (Recommended)

The `fullSync()` method handles all entity types in the correct dependency order:

1. **Accounts** (CRM → Daktela) — synced first
2. **Relation maps built** — maps CRM account IDs to Daktela account names
3. **Contacts** (CRM → Daktela) — account references resolved automatically
4. **Activities** (Daktela → CRM)

```php
$results = $engine->fullSync();

foreach ($results as $entityType => $result) {
    echo sprintf(
        "%s: %d total, %d created, %d updated, %d failed\n",
        $entityType,
        $result->getTotalCount(),
        $result->getCreatedCount(),
        $result->getUpdatedCount(),
        $result->getFailedCount(),
    );
}
```

Only enabled entities (per config) are synced. Disabled entities are skipped.

## Individual Batch Sync

Process a single entity type:

```php
// Contacts: CRM → Daktela
$result = $engine->syncContactsBatch();

// Accounts: CRM → Daktela
$result = $engine->syncAccountsBatch();

// Activities: Daktela → CRM
$result = $engine->syncActivitiesBatch();
$result = $engine->syncActivitiesBatch([ActivityType::Call, ActivityType::Email]);
```

**Important:** When syncing individually, sync accounts before contacts if your contact mappings have relation configs. `fullSync()` handles this automatically.

Batch sync respects the `batch_size` setting in configuration.

## Single-Record Sync

For webhook-triggered sync of individual records:

```php
$result = $engine->syncContact('crm-contact-id');
$result = $engine->syncAccount('crm-account-id');
$result = $engine->syncActivity('call-123', ActivityType::Call);
```

## SyncResult

Every sync operation returns a `SyncResult`:

```php
$result->getTotalCount();    // Total records processed
$result->getCreatedCount();  // New records created in target
$result->getUpdatedCount();  // Existing records updated
$result->getSkippedCount();  // Records skipped (e.g., not found)
$result->getFailedCount();   // Records that failed
$result->getDuration();      // Time in seconds
$result->getRecords();       // All RecordResult objects
$result->getFailedRecords(); // Only failed RecordResult objects
```

Each `RecordResult` contains:

```php
$record->entityType;    // 'contact', 'account', 'activity'
$record->sourceId;      // ID in source system
$record->targetId;      // ID in target system (null if failed)
$record->status;        // SyncStatus enum: Created, Updated, Skipped, Failed
$record->errorMessage;  // Error details if failed
```

## Contact → Account Relationship

Daktela contacts reference accounts by the account's `name` field (unique identifier). When syncing contacts from a CRM, the CRM typically uses its own internal account IDs.

The SDK resolves this automatically when you:

1. Configure a `relation` in your contact mapping YAML:

```yaml
# contacts.yaml
- source: account          # Daktela field
  target: company_id       # CRM field
  direction: crm_to_cc
  relation:
    entity: account        # Related entity
    resolve_from: id       # CRM account field to match
    resolve_to: name       # Daktela account field to use
```

2. Use `fullSync()` or sync accounts before contacts.

The engine builds a map like:
```
CRM account.id    → CRM account.external_id (mapped to Daktela name)
"crm-acc-123"     → "acme"
"crm-acc-456"     → "globex"
```

Then when a CRM contact has `company_id = "crm-acc-123"`, it gets resolved to `account = "acme"` in Daktela.

## Error Handling

The sync engine catches per-record errors and continues processing. Failed records are captured in `SyncResult::getFailedRecords()` rather than throwing exceptions.

```php
$result = $engine->syncContactsBatch();

if ($result->getFailedCount() > 0) {
    foreach ($result->getFailedRecords() as $failed) {
        $logger->error('Sync failed for {type} {id}: {msg}', [
            'type' => $failed->entityType,
            'id' => $failed->sourceId,
            'msg' => $failed->errorMessage,
        ]);
    }
}
```

## Custom Transformer Registry

Pass a custom `TransformerRegistry` to the engine:

```php
$registry = TransformerRegistry::withDefaults();
$registry->register(new MyCustomTransformer());

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, $registry);
```

## Incremental Sync

By default, every batch sync fetches all records. To enable incremental sync, pass a `SyncStateStoreInterface` implementation to the engine. The SDK ships with `FileSyncStateStore`:

```php
use Daktela\CrmSync\State\FileSyncStateStore;

$stateStore = new FileSyncStateStore('/var/data/myapp/sync-state.json');

$engine = new SyncEngine(
    ccAdapter: $ccAdapter,
    crmAdapter: $crmAdapter,
    config: $config,
    logger: $logger,
    stateStore: $stateStore,
);
```

**Behavior:**

- **First run** (no saved state) — full sync, same as without a state store
- **Subsequent runs** — the saved timestamp is passed as `$since` to adapter `iterate*()` methods, so only records modified since the last successful sync are returned

**When state is saved:**

State is saved only when the batch completes successfully — meaning all records were processed (batch limit not hit) and none failed. This prevents skipping records due to partial syncs. See the [Production Deployment](09-production-deployment.md) guide for details on safety guarantees.

## Force Full Sync

Ignore saved state and sync all records:

```php
$results = $engine->fullSync(forceFullSync: true);
```

Use cases:
- Initial data load into a fresh Daktela instance
- Recovery after data corruption or manual changes
- After modifying field mapping configuration

The force flag is temporary — it only applies to that single `fullSync()` call. Subsequent calls resume incremental behavior.

## Reset State

Clear saved timestamps so the next run starts from scratch:

```php
// Reset all entity types — next fullSync() will be a full sync
$engine->resetState();

// Reset a single entity type — only that entity re-syncs fully
$engine->resetState('contact');
```

If no state store is configured, `resetState()` is a no-op.
