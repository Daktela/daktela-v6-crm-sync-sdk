# Daktela CRM Sync

A universal sync layer between **Daktela Contact Centre V6** and any CRM system. Ships a concrete Daktela adapter, ready-to-use CRM integrations, and lets you add more under `src/Crm/`.

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
  CRM adapters live
  in src/Crm/<Name>/
```

**Sync directions:**
- **Contacts**: CRM → Daktela (CRM is source-of-truth)
- **Accounts**: CRM → Daktela (CRM is source-of-truth)
- **Activities**: Daktela → CRM (Daktela is source-of-truth)

## CRM Integrations

| CRM | Namespace | Docs |
|-----|-----------|------|
| [Raynet CRM](https://raynet.cz) | `Daktela\CrmSync\Crm\Raynet` | [README](src/Crm/Raynet/README.md) |

## Requirements

- PHP 8.2+
- Daktela V6 instance with API access

## Installation

```bash
composer require daktela/daktela-v6-crm-sync
```

## Quick Start

1. Create your CRM adapter implementing `CrmAdapterInterface`
2. Configure field mappings in YAML
3. Wire up the `SyncEngine`

```php
use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Sync\SyncEngine;
use Psr\Log\NullLogger;

$config = (new YamlConfigLoader())->load('config/sync.yaml');
$logger = new NullLogger();

$ccAdapter = new DaktelaAdapter($config->instanceUrl, $config->accessToken, $config->database, $logger);
$crmAdapter = new YourCrmAdapter(/* ... */);

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger);
$result = $engine->syncContactsBatch();
```

For Raynet CRM specifically, see the [Raynet README](src/Crm/Raynet/README.md) and [`examples/raynet/`](examples/raynet/).

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
docker compose run --rm php composer install
docker compose run --rm php vendor/bin/phpunit
docker compose run --rm php vendor/bin/phpstan analyse
```

## License

Proprietary — requires a valid Daktela Contact Centre license. See [LICENSE](LICENSE) for details.
