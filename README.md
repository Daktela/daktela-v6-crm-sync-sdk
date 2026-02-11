# Daktela CRM Sync SDK

A universal sync layer between **Daktela Contact Centre V6** and any CRM system. This SDK provides adapter interfaces for both sides, ships a concrete Daktela adapter, and lets you implement only the CRM side.

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────────┐
│  CRM System │ ──▶ │ Sync Engine │ ──▶ │ Daktela CC V6   │
│  (Adapter)  │ ◀── │  + Mapper   │ ◀── │   (Adapter)     │
└─────────────┘     └─────────────┘     └─────────────────┘
      │                    │                     │
      │              YAML Configs          Official PHP
      │            (field mappings)        Connector v2.4
      │
  You implement this
```

**Sync directions:**
- **Contacts**: CRM → Daktela (CRM is source-of-truth)
- **Accounts**: CRM → Daktela (CRM is source-of-truth)
- **Activities**: Daktela → CRM (Daktela is source-of-truth)

## Requirements

- PHP 8.2+
- Daktela V6 instance with API access

## Installation

```bash
composer require daktela/crm-sync
```

## Quick Start

1. Create your CRM adapter implementing `CrmAdapterInterface`
2. Configure field mappings in YAML
3. Wire up the `SyncEngine`

```php
use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\State\FileSyncStateStore;
use Daktela\CrmSync\Sync\SyncEngine;
use Psr\Log\NullLogger;

$config = (new YamlConfigLoader())->load('config/sync.yaml');
$logger = new NullLogger();

$ccAdapter = new DaktelaAdapter(
    $config->instanceUrl,
    $config->accessToken,
    $logger,
);

$crmAdapter = new YourCrmAdapter(/* ... */);

// Optional: enable incremental sync by providing a state store
$stateStore = new FileSyncStateStore('/var/data/myapp/sync-state.json');

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, stateStore: $stateStore);

// Batch sync contacts from CRM to Daktela
$result = $engine->syncContactsBatch();

echo sprintf(
    "Synced %d contacts: %d created, %d updated, %d failed\n",
    $result->getTotalCount(),
    $result->getCreatedCount(),
    $result->getUpdatedCount(),
    $result->getFailedCount(),
);
```

## Examples

Ready-to-use examples in the [`examples/`](examples/) directory:

| File | Description |
|------|-------------|
| [`bootstrap.php`](examples/bootstrap.php) | Shared setup — config, adapters, engine |
| [`full-sync.php`](examples/full-sync.php) | Full sync of all entities |
| [`single-entity-sync.php`](examples/single-entity-sync.php) | Sync contacts, accounts, or activities individually |
| [`single-record-sync.php`](examples/single-record-sync.php) | Sync a single record by ID |
| [`incremental-sync.php`](examples/incremental-sync.php) | Incremental sync with state tracking |
| [`webhook-daktela.php`](examples/webhook-daktela.php) | Daktela webhook endpoint |

## Documentation

- [Getting Started](docs/01-getting-started.md)
- [Configuration](docs/02-configuration.md)
- [Field Mapping](docs/03-field-mapping.md)
- [Implementing a CRM Adapter](docs/04-implementing-crm-adapter.md)
- [Sync Engine](docs/05-sync-engine.md)
- [Webhooks](docs/06-webhooks.md)
- [Error Handling](docs/07-error-handling.md)
- [Testing Your Integration](docs/08-testing-your-integration.md)
- [Production Deployment](docs/09-production-deployment.md)

## Development

```bash
docker compose build
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse
```

## License

Proprietary — requires a valid Daktela Contact Centre license. See [LICENSE](LICENSE) for details.
