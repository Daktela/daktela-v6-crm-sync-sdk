# Getting Started

## Installation

```bash
composer require daktela/crm-sync
```

## Prerequisites

- PHP 8.2 or higher
- A Daktela V6 instance with API access token
- A CRM system you want to integrate

## Quick Overview

The SDK syncs three entity types between Daktela and your CRM:

| Entity | Direction | Source of Truth |
|--------|-----------|-----------------|
| Contacts | CRM → Daktela | CRM |
| Accounts | CRM → Daktela | CRM |
| Activities | Daktela → CRM | Daktela |

## Basic Setup

### 1. Create Configuration Files

Create a `config/sync.yaml` file:

```yaml
daktela:
  instance_url: "https://your-instance.daktela.com"
  access_token: "${DAKTELA_ACCESS_TOKEN}"

sync:
  batch_size: 100
  entities:
    contact:
      enabled: true
      direction: crm_to_cc
      mapping_file: "mappings/contacts.yaml"
    account:
      enabled: true
      direction: crm_to_cc
      mapping_file: "mappings/accounts.yaml"
    activity:
      enabled: true
      direction: cc_to_crm
      mapping_file: "mappings/activities.yaml"
      activity_types: [call, email]

webhook:
  secret: "${WEBHOOK_SECRET}"
```

### 2. Create Field Mappings

Create `config/mappings/contacts.yaml`:

```yaml
entity: contact
lookup_field: email
mappings:
  - source: title
    target: full_name
    direction: crm_to_cc
  - source: email
    target: email
    direction: crm_to_cc
  - source: number
    target: phone
    direction: crm_to_cc
    transformers:
      - name: phone_normalize
        params: { format: e164 }
  - source: account
    target: company_id
    direction: crm_to_cc
    relation:
      entity: account
      resolve_from: id
      resolve_to: name
```

Create `config/mappings/accounts.yaml`:

```yaml
entity: account
lookup_field: name
mappings:
  - source: title
    target: company_name
    direction: crm_to_cc
  - source: name
    target: external_id
    direction: crm_to_cc
```

Create `config/mappings/activities.yaml`:

```yaml
entity: activity
lookup_field: name
mappings:
  - source: name
    target: external_id
    direction: cc_to_crm
  - source: title
    target: subject
    direction: cc_to_crm
  - source: time_start
    target: start_time
    direction: cc_to_crm
    transformers:
      - name: date_format
        params: { from: "Y-m-d H:i:s", to: "c" }
```

### 3. Implement Your CRM Adapter

Create a class that implements `CrmAdapterInterface`. This is where you connect to your specific CRM system (Salesforce, HubSpot, Dynamics, etc.).

See [Implementing a CRM Adapter](04-implementing-crm-adapter.md) for a complete guide with examples.

### 4. Wire Everything Together

```php
use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Config\YamlConfigLoader;
use Daktela\CrmSync\Sync\SyncEngine;
use Psr\Log\NullLogger;

// Load configuration
$config = (new YamlConfigLoader())->load(__DIR__ . '/config/sync.yaml');

// Create adapters
$ccAdapter = new DaktelaAdapter(
    $config->instanceUrl,
    $config->accessToken,
    new NullLogger(),
);

$crmAdapter = new YourCrmAdapter(/* your CRM connection params */);

// Create the sync engine
$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, new NullLogger());
```

### 5. Run a Sync

**Full sync** (recommended — handles dependencies automatically):

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

**Individual entity sync:**

```php
// Sync accounts first (required if contacts reference accounts)
$engine->syncAccountsBatch();

// Then sync contacts
$result = $engine->syncContactsBatch();

// Sync activities
$result = $engine->syncActivitiesBatch();
```

> **Tip:** The [`examples/`](../examples/) directory contains ready-to-run scripts for all sync
> scenarios — full sync, single entity, single record, incremental, and webhooks.

### 6. Set Up Webhooks (Optional)

For real-time sync triggered by Daktela events, see [Webhooks](06-webhooks.md).

## Next Steps

- [Examples](../examples/) — Ready-to-run scripts for all sync scenarios
- [Configuration Reference](02-configuration.md) — All YAML config options
- [Field Mapping](03-field-mapping.md) — Transformers, multi-value fields, relations
- [Implementing a CRM Adapter](04-implementing-crm-adapter.md) — Complete adapter guide
- [Sync Engine](05-sync-engine.md) — Batch sync, webhook sync, fullSync
- [Webhooks](06-webhooks.md) — Real-time sync with Daktela events
- [Error Handling](07-error-handling.md) — Exceptions, logging, debugging
- [Testing Your Integration](08-testing-your-integration.md) — Test strategies
- [Production Deployment](09-production-deployment.md) — Cron, logging, monitoring
