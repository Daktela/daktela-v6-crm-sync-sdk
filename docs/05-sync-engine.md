# Sync Engine

The `SyncEngine` orchestrates syncing between adapters using field mappings.

## Full Sync (Recommended)

The `fullSync()` method handles all entity types in the correct dependency order:

1. **Accounts** (CRM → Daktela) — synced first, relation map populated automatically
2. **Contacts** (CRM → Daktela) — account references resolved via relation map; missing accounts auto-fetched on-the-fly
3. **Activities** (Daktela → CRM)

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

**Note:** If a contact references an account that hasn't been synced yet, `BatchSync` automatically fetches it from the CRM and syncs it on-the-fly. Syncing accounts before contacts is still recommended for efficiency (avoids per-contact lookups), but is no longer required.

Batch sync respects the `batch_size` setting in configuration. Each call processes up to `batch_size` records and tracks its offset internally, so the next call continues where the previous one left off. `fullSync()` automatically loops through all records in batches, while individual batch methods (`syncContactsBatch()`, `syncAccountsBatch()`, `syncActivitiesBatch()`) process a single batch per call — callers can loop externally if needed.

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
$result->isExhausted();      // True if all source records were processed (no more batches)
$result->merge($other);      // Merge another SyncResult into this one (used by fullSync internally)
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
- cc_field: account        # Daktela field
  crm_field: company_id    # CRM field
  relation:
    entity: account        # Related entity
    resolve_from: id       # CRM account field to match
    resolve_to: name       # Daktela account field to use
```

2. Use `fullSync()` (recommended) or sync accounts before contacts. If a contact references an account not yet in the relation map, the engine automatically fetches it from the CRM and syncs it on-the-fly.

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

## Change Detection

When upserting contacts and accounts to Daktela, the adapter compares mapped field values against the existing record. If no fields have changed, the PUT API call is skipped entirely and the record is counted as "skipped" in `SyncResult`. This saves one API call per unchanged record during incremental syncs.

- **Record with changes:** 1 find + 1 PUT = 2 API calls (same as before)
- **Record with no changes:** 1 find = 1 API call (saves 1 PUT)

Skipped records are still tracked in relation maps and appear in `SyncResult::getSkippedCount()`.

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
