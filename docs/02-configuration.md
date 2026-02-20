# Configuration

All configuration is done via YAML files. The main config file references per-entity mapping files.

## Main Configuration (`sync.yaml`)

```yaml
daktela:
  instance_url: "https://your-instance.daktela.com"
  access_token: "${DAKTELA_ACCESS_TOKEN}"
  database: "default"

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

## Configuration Reference

### `daktela`
| Key | Type | Description |
|-----|------|-------------|
| `instance_url` | string | Your Daktela instance URL (e.g., `https://acme.daktela.com`) |
| `access_token` | string | API access token (create in Daktela: Manage → Users → API tokens) |
| `database` | string | Database/segment for Contacts & Accounts (e.g., `default`) |

### `sync`
| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `batch_size` | int | 100 | Max records per batch sync run per entity type |

### `sync.entities.<type>`

Each entity type (`contact`, `account`, `activity`) can be configured independently:

| Key | Type | Description |
|-----|------|-------------|
| `enabled` | bool | Whether this entity sync is active |
| `direction` | string | `crm_to_cc`, `cc_to_crm`, or `bidirectional` |
| `mapping_file` | string | Path to YAML mapping file (relative to config dir) |
| `activity_types` | array | For activities only: which types to sync |

### Activity Types

Available activity types for the `activity_types` config:

| Value | Channel |
|-------|---------|
| `call` | Phone calls |
| `email` | Emails |
| `web` | Web chat |
| `sms` | SMS messages |
| `fbm` | Facebook Messenger |
| `wap` | WhatsApp |
| `vbr` | Viber |

### `webhook`
| Key | Type | Description |
|-----|------|-------------|
| `secret` | string | Shared secret for webhook validation (set in Daktela automation headers) |

## Environment Variables

Use `${ENV_VAR}` syntax to reference environment variables. This keeps secrets out of YAML files:

```yaml
daktela:
  access_token: "${DAKTELA_ACCESS_TOKEN}"

webhook:
  secret: "${WEBHOOK_SECRET}"
```

The loader resolves these at load time using `getenv()`. If the environment variable is not set, the raw `${...}` string is kept.

Environment variable resolution also works in mapping YAML files (not just `sync.yaml`). Inline interpolation is supported — `"prefix${VAR}suffix"` resolves the variable while keeping the surrounding text. This is useful for URL templates:

```yaml
# In a mapping file
transformers:
  - name: url
    params: { template: "https://app.raynet.cz/${RAYNET_INSTANCE_NAME}/?view=DetailView&en=Person&ei={value}" }
```

Here `${RAYNET_INSTANCE_NAME}` resolves at config load time, while `{value}` is a transformer placeholder replaced at runtime.

## Loading Configuration

```php
use Daktela\CrmSync\Config\YamlConfigLoader;

$config = (new YamlConfigLoader())->load(__DIR__ . '/config/sync.yaml');

// Access values
$config->instanceUrl;     // "https://your-instance.daktela.com"
$config->accessToken;     // resolved from env var
$config->database;        // "default"
$config->batchSize;       // 100
$config->webhookSecret;   // resolved from env var

// Entity configs
$config->isEntityEnabled('contact');           // true
$config->getEntityConfig('contact');           // EntitySyncConfig
$config->getEntityConfig('contact')->direction; // SyncDirection::CrmToCc

// Mappings (loaded from referenced YAML files)
$config->getMapping('contact');  // MappingCollection
$config->getMapping('account');  // MappingCollection
```

## Mapping File Schema

Each mapping file defines how fields are translated. See [Field Mapping](03-field-mapping.md) for full reference.

Minimal example:

```yaml
entity: contact
lookup_field: email
mappings:
  - cc_field: title           # Daktela field
    crm_field: full_name      # CRM field
  - cc_field: email
    crm_field: email
```

Extended with all features:

```yaml
entity: contact
lookup_field: email
mappings:
  - cc_field: number
    crm_field: phone
    transformers:
      - name: phone_normalize
        params: { format: e164 }
  - cc_field: account
    crm_field: company_id
    relation:
      entity: account
      resolve_from: id
      resolve_to: name
  - cc_field: customFields.tags
    crm_field: tags
    multi_value:
      strategy: split
      separator: ","
```
