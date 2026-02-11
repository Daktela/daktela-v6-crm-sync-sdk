# Error Handling

## Exception Hierarchy

```
\RuntimeException
  └── SyncException              # Base exception
        ├── AdapterException     # Adapter read/write failures
        ├── MappingException     # Transformer/mapping issues
        └── ConfigurationException # Config file issues
```

All SDK exceptions extend `SyncException`, which extends `\RuntimeException`. You can catch `SyncException` to handle all SDK-specific errors.

## AdapterException

Thrown when adapter operations fail (API errors, network issues, etc.):

```php
use Daktela\CrmSync\Exception\AdapterException;

// Static factory methods:
AdapterException::readFailed('contact', 'c-123');     // "Failed to read contact: c-123"
AdapterException::createFailed('contact', $previous); // "Failed to create contact" with cause
AdapterException::updateFailed('contact', 'c-123');   // "Failed to update contact: c-123"
AdapterException::missingId('contact');                // "Missing ID for contact"
```

Use these in your CRM adapter implementation:

```php
public function createActivity(Activity $activity): Activity
{
    try {
        $id = $this->client->create('Task', $activity->toArray());
        return Activity::fromArray(array_merge($activity->toArray(), ['id' => $id]));
    } catch (\Throwable $e) {
        throw AdapterException::createFailed('activity', $e);
    }
}
```

## MappingException

Thrown for mapping configuration issues at runtime:

```php
use Daktela\CrmSync\Exception\MappingException;

MappingException::unknownTransformer('nonexistent_transform');
```

This is thrown when a YAML mapping references a transformer name that isn't registered in the `TransformerRegistry`.

## ConfigurationException

Thrown during config loading when files are missing or invalid:

```php
use Daktela\CrmSync\Exception\ConfigurationException;

ConfigurationException::fileNotFound('/path/to/config.yaml');
ConfigurationException::invalidMappingFile('/path/to/mapping.yaml', 'Missing entity key');
```

Common causes:
- YAML file path doesn't exist
- Missing required keys (`entity`, `lookup_field`, `mappings`)
- Invalid `direction` value (must be `crm_to_cc`, `cc_to_crm`, or `bidirectional`)
- Invalid `multi_value.strategy` (must be `as_array`, `join`, `split`, `first`, or `last`)
- Incomplete `relation` config (requires `entity`, `resolve_from`, and `resolve_to`)

## Per-Record Error Handling

The sync engine does **not** throw on individual record failures. Instead, failures are captured in `SyncResult` and processing continues with the next record:

```php
$result = $engine->syncContactsBatch();

// Check for failures
if ($result->getFailedCount() > 0) {
    foreach ($result->getFailedRecords() as $failed) {
        $logger->error('Failed to sync {type} {id}: {error}', [
            'type' => $failed->entityType,
            'id' => $failed->sourceId,
            'error' => $failed->errorMessage,
        ]);
    }
}
```

This design ensures that one bad record doesn't stop the entire batch. Each `RecordResult` contains:

```php
$record->entityType;    // 'contact', 'account', 'activity'
$record->sourceId;      // ID in the source system
$record->targetId;      // ID in the target system (null if failed)
$record->status;        // SyncStatus: Created, Updated, Skipped, Failed
$record->errorMessage;  // Error details (null if successful)
```

## fullSync() Error Handling

When using `fullSync()`, each entity type has its own `SyncResult`. A failure in account sync does not prevent contact sync from running:

```php
$results = $engine->fullSync();

foreach ($results as $entityType => $result) {
    if ($result->getFailedCount() > 0) {
        $logger->warning('{type} sync had {count} failures', [
            'type' => $entityType,
            'count' => $result->getFailedCount(),
        ]);
    }
}
```

## Webhook Error Handling

The webhook handler returns appropriate HTTP status codes:

| Code | Meaning |
|------|---------|
| `200` | All records synced successfully |
| `207` | Partial success (some records failed) |
| `401` | Invalid webhook secret |
| `500` | Handler error (exception during processing) |

```php
$webhookResult = $handler->handle($request);

// The result includes the HTTP status code
http_response_code($webhookResult->httpStatusCode);
echo json_encode($webhookResult->toResponseArray());
```

## Logging

The SDK uses PSR-3 logging. Pass any `LoggerInterface` implementation:

```php
$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger);
```

Log levels used:

| Level | When |
|-------|------|
| `info` | Batch sync completion summaries, relation map build stats |
| `warning` | Invalid webhook secrets, missing relation map fields |
| `error` | Per-record sync failures, webhook handling errors |
| `debug` | Entity not found during lookups |

## Debugging Tips

**Relation maps not resolving:**
- Ensure accounts are synced before contacts (use `fullSync()`)
- Check that your account mapping includes the `resolve_to` field (e.g., `name`)
- Check logs for "Cannot build relation map" warnings

**Records skipped unexpectedly:**
- In webhook sync, a `Skipped` status means the source entity wasn't found by ID
- Check that the ID in the webhook payload matches a valid CRM/CC record

**Transformer errors:**
- Ensure the transformer name in YAML matches a registered transformer
- Check that required params are provided (e.g., `date_format` needs `from` and `to`)
