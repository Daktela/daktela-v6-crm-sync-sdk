# Raynet CRM Integration

Raynet CRM adapter for Daktela CRM Sync. Synchronizes contacts, accounts, and activities between Daktela Contact Centre V6 and Raynet CRM.

## How It Works

- **`RaynetCrmAdapter`** — implements `CrmAdapterInterface`, translating between Raynet's nested API responses and the flat entity model the SDK expects.
- **`RaynetClient`** — a lightweight Guzzle-based HTTP client that handles Raynet's API quirks (reversed PUT/POST, pagination, auth headers).

All sync orchestration, field mapping, transformations, and batch processing are handled by the core SDK. This integration only deals with Raynet-specific concerns.

| Entity     | Direction              | Source of truth |
|------------|------------------------|-----------------|
| Contacts   | Raynet &rarr; Daktela  | Raynet CRM      |
| Accounts   | Raynet &rarr; Daktela  | Raynet CRM      |
| Activities | Daktela &rarr; Raynet  | Daktela CC      |

## Quick Start

`SyncEngineFactory` wires everything from a single YAML config:

```php
use Daktela\CrmSync\Sync\SyncEngineFactory;

$factory = SyncEngineFactory::fromYaml('config/sync.yaml', stateStorePath: 'var/sync-state.json');
$engine = $factory->getEngine();

$engine->testConnections();

$results = $engine->fullSync();
foreach ($results->toArray() as $type => $result) {
    echo $result->getSummary(ucfirst($type)) . "\n";
}
```

See [`examples/raynet/`](../../../examples/raynet/) for full sync, incremental, single-record, and webhook examples.

## Configuration

Copy the distribution config files into your project:

```bash
mkdir -p config/mappings
cp config/raynet/sync.yaml config/sync.yaml
cp config/raynet/mappings/*.yaml config/mappings/
```

Then edit `config/sync.yaml` with your credentials and customize the mapping files.

### sync.yaml

The `raynet` section is specific to this integration. See the [SDK configuration docs](../../docs/02-configuration.md) for the `daktela`, `sync`, and `webhook` sections.

```yaml
raynet:
  api_url: "https://app.raynet.cz/api/v2/"
  email: "${RAYNET_EMAIL}"
  api_key: "${RAYNET_API_KEY}"
  instance_name: "${RAYNET_INSTANCE_NAME}"
  person_type: "person"             # "person" or "contact-person"
  owner_id: 0                       # Raynet user ID for activity ownership
```

Use `${ENV_VAR}` placeholders to keep secrets out of version control.

## Mapping Files

For full field mapping syntax (directions, dot notation, multi-value strategies, relations, transformer chains), see the [SDK field mapping docs](../../docs/03-field-mapping.md).

### Contacts

The adapter passes through raw Raynet API fields. Use dot notation in mappings to access nested values. Common fields:

| CRM field path                     | Description                        |
|------------------------------------|------------------------------------|
| `firstName`                        | First name                         |
| `lastName`                         | Last name                          |
| `fullName`                         | Full name (computed by Raynet API) |
| `contactInfo.email`                | Primary email                      |
| `contactInfo.tel1`                 | Primary phone                      |
| `primaryRelationship.company.id`   | Related company ID                 |
| `id`                               | Raynet person ID                   |

### Accounts

| CRM field path                         | Description         |
|----------------------------------------|---------------------|
| `name`                                 | Company name        |
| `regNumber`                            | Registration number |
| `primaryAddress.contactInfo.email`     | Company email       |
| `primaryAddress.contactInfo.tel1`      | Company phone       |
| `id`                                   | Raynet company ID   |

### Activities

Activities flow from Daktela to Raynet. The adapter maps Daktela activity types to Raynet endpoints:

| Daktela type | Raynet endpoint | Description        |
|--------------|-----------------|--------------------|
| `call`       | `phoneCall/`    | Phone call         |
| `email`      | `email/`        | Email              |
| `web`        | `task/`         | Web chat           |
| `sms`        | `task/`         | SMS                |
| `fbm`        | `task/`         | Facebook Messenger |
| `wap`        | `task/`         | WhatsApp           |
| `vbr`        | `task/`         | Viber              |

Raynet activity fields available for mapping:

| Field           | Description                      |
|-----------------|----------------------------------|
| `subject`       | Activity subject/title           |
| `scheduledFrom` | Start time (`Y-m-d H:i` format) |
| `scheduledTill` | End time (`Y-m-d H:i` format)   |
| `externalId`    | External identifier for upsert   |
| `ownerEmail`    | Owner resolution by email        |
| `ownerLogin`    | Owner resolution by login        |

## Raynet API Quirks

### Reversed HTTP Methods

Raynet uses `PUT` for creation and `POST` for updates — the reverse of typical REST conventions. The adapter handles this internally.

### Person Type

Raynet has two endpoints for people:

- **`/person/`** — standalone contacts (not linked to a company)
- **`/contact-person/`** — contacts linked to a company

Set `person_type` in config to choose which endpoint to use. Default is `person`.

### Activity Endpoints

Unlike many CRMs, Raynet has separate endpoints per activity type: `phoneCall/`, `email/`, `task/`, `meeting/`, `event/`, `letter/`. The adapter routes automatically based on the Daktela activity type.

### External IDs

External IDs are stored as `extIds` array, set via `PUT {entity}/{id}/extId/` with `{"extId":"value"}`. Lookup by external ID: `GET {entity}/ext/{extId}/`. The `externalId` field is NOT a filterable column — you must use the `/ext/` endpoint.

### Owner Field on Activities

The `owner` field on activities cannot be sent on POST updates (returns 403). The adapter only sends `owner` on creation.

### Rate Limits

Raynet enforces a daily limit of 24,000 API requests and a maximum of 4 concurrent connections. The adapter throws `RaynetRateLimitException` when the limit is reached (HTTP 429). Use `batch_size` in `sync.yaml` to control how many records are processed per run.

## Examples

Ready-to-use examples in [`examples/raynet/`](../../../examples/raynet/):

| File | Description |
|------|-------------|
| `bootstrap.php` | Shared setup — config, both adapters, engine |
| `full-sync.php` | Full sync of all entities |
| `single-entity-sync.php` | Sync entities individually |
| `single-record-sync.php` | Sync a single record by ID |
| `incremental-sync.php` | Incremental sync with state tracking |
| `webhook-daktela.php` | Daktela webhook endpoint |
