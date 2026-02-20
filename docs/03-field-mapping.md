# Field Mapping

Field mappings define how data is translated between Daktela CC fields and CRM fields. Each entity type (contact, account, activity) has its own YAML mapping file.

## YAML Schema

```yaml
entity: contact          # Entity type
lookup_field: email      # Field used for upsert lookups
mappings:
  - cc_field: title      # Daktela CC field name
    crm_field: full_name # CRM field name
    transformers:        # Optional value transformers
      - name: string_case
        params: { case: title }
    multi_value:         # Optional multi-value handling
      strategy: join
      separator: ", "
    relation:            # Optional cross-entity reference
      entity: account
      resolve_from: id
      resolve_to: name
```

## Direction

Sync direction is configured at the **entity level** in `sync.yaml`, not per field. All field mappings within an entity follow the entity's direction. The mapping engine automatically reads from the correct side based on direction:

- `crm_to_cc` — reads CRM fields (`crm_field`), writes CC fields (`cc_field`)
- `cc_to_crm` — reads CC fields (`cc_field`), writes CRM fields (`crm_field`)

## Dot Notation for Nested Fields

Access nested fields (such as Daktela's `customFields`) using dots:

```yaml
- cc_field: customFields.industry
  crm_field: industry
```

This reads/writes `$entity['customFields']['industry']`. Intermediate arrays are created automatically when writing.

You can also nest on the CRM side:

```yaml
- cc_field: email
  crm_field: contact_info.email
```

---

## Multi-Value Custom Fields

Daktela custom fields can store multiple values as arrays (e.g., tags, categories, interests). The `multi_value` config controls how array values are converted between systems.

### Strategies

| Strategy | Direction hint | Description |
|----------|---------------|-------------|
| `as_array` | Both | Keep value as an array, wrap scalars in `[]` |
| `join` | Array → String | Join array elements with separator into a string |
| `split` | String → Array | Split a string by separator into an array |
| `first` | Array → Scalar | Take the first element of an array |
| `last` | Array → Scalar | Take the last element of an array |

### Examples

**CRM stores tags as comma-separated string, Daktela stores as array:**

```yaml
# CRM "web,mobile,api" → Daktela ["web", "mobile", "api"]
- cc_field: customFields.tags
  crm_field: tags
  multi_value:
    strategy: split
    separator: ","
```

**Daktela stores interests as array, CRM wants a joined string:**

```yaml
# Daktela ["sports", "music"] → CRM "sports, music"
- cc_field: customFields.interests
  crm_field: interests
  multi_value:
    strategy: join
    separator: ", "
```

**Take only the first value from a multi-value field:**

```yaml
- cc_field: customFields.disposition
  crm_field: primary_disposition
  multi_value:
    strategy: first
```

**Pass arrays as-is (both systems support arrays):**

```yaml
- cc_field: customFields.categories
  crm_field: categories
  multi_value:
    strategy: as_array
```

### Processing Order

For each field mapping, processing happens in this order:
1. Read source value
2. Apply transformer chain
3. Resolve relations
4. Apply multi-value strategy (non-append fields only)
5. Write to target (append or set)

For `append` fields, the `multi_value` strategy is deferred — it runs once after all values for that target field are accumulated. This allows `multi_value: join` to collapse the final array into a string.

---

## Relations (Cross-Entity References)

When syncing contacts, you often need to resolve references to other entities. For example, a CRM contact has a `company_id` that references a CRM account, but Daktela's `account` field expects the Daktela account `name`.

### Configuration

```yaml
- cc_field: account          # Daktela CC field
  crm_field: company_id      # CRM field
  relation:
    entity: account        # The related entity type
    resolve_from: id       # Match CRM account by this field
    resolve_to: name       # Use this Daktela field as the resolved value
```

### How It Works

1. During `fullSync()`, accounts are synced first
2. The engine builds a resolution map: `CRM account.id → Daktela account.name`
3. When syncing contacts, the mapper sees `company_id = "crm-acc-123"` and resolves it to `account = "acme"` using the map
4. If a value cannot be resolved, the original value is passed through unchanged

### Using fullSync()

The `SyncEngine::fullSync()` method handles the correct dependency order automatically:

```php
$results = $engine->fullSync();

// $results['account'] — SyncResult for accounts
// $results['contact'] — SyncResult for contacts (with resolved account refs)
// $results['activity'] — SyncResult for activities
```

If you sync entities individually, you need to sync accounts before contacts:

```php
$engine->syncAccountsBatch(); // Must come first
$engine->syncContactsBatch(); // Can now resolve account references
```

---

## Built-in Transformers

### `date_format`
Converts between date formats using PHP's `DateTimeImmutable`.

```yaml
transformers:
  - name: date_format
    params:
      from: "Y-m-d H:i:s"   # Source format
      to: "c"                # Target format (ISO 8601)
```

If the source value doesn't match the `from` format, the transformer attempts generic parsing as a fallback.

### `phone_normalize`
Strips all non-digit/non-plus characters and optionally prepends `+` for E.164 format.

```yaml
transformers:
  - name: phone_normalize
    params: { format: e164 }
```

Example: `"(420) 123-456-789"` → `"+420123456789"`

### `boolean`
Casts to boolean. Recognizes these string values as truthy: `"true"`, `"yes"`, `"1"`, `"on"` (case-insensitive).

```yaml
transformers:
  - name: boolean
```

### `string_case`
Changes string case. Supported values for `case` param: `lower`, `upper`, `title`.

```yaml
transformers:
  - name: string_case
    params: { case: lower }
```

### `default_value`
Provides a fallback when the source value is `null`.

```yaml
transformers:
  - name: default_value
    params: { value: "N/A" }
```

### `callback`
Runs a registered PHP closure. Register callbacks on the `CallbackTransformer` before creating the engine:

```php
$registry = TransformerRegistry::withDefaults();
$callback = $registry->get('callback');
assert($callback instanceof CallbackTransformer);
$callback->registerCallback('normalize_country', function (mixed $value): string {
    return match (strtolower((string) $value)) {
        'cz', 'czech republic', 'czechia' => 'CZ',
        'sk', 'slovakia' => 'SK',
        default => strtoupper((string) $value),
    };
});

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, $registry);
```

```yaml
transformers:
  - name: callback
    params: { name: normalize_country }
```

### `prefix`
Prepends a string to the value. Useful for creating unique IDs with CRM-specific prefixes.

```yaml
transformers:
  - name: prefix
    params: { value: "raynet_" }
```

Example: `"12345"` → `"raynet_12345"`

Null and empty string values are returned unchanged.

### `strip_prefix`
Removes a prefix from the beginning of a string. The inverse of `prefix` — useful for extracting the original ID from a prefixed value.

```yaml
transformers:
  - name: strip_prefix
    params: { value: "raynet_" }
```

Example: `"raynet_12345"` → `"12345"`. If the value doesn't start with the prefix, it is returned unchanged.

### `wrap_array`
Wraps a scalar value in an array. Already-array values are returned as-is, null/empty values become `[]`. Useful when Daktela expects array custom fields but the CRM provides a single value.

```yaml
transformers:
  - name: wrap_array
```

Example: `"john@example.com"` → `["john@example.com"]`

### `url`
Builds a URL from a template with a `{value}` placeholder. Useful for generating CRM detail links stored in Daktela's description field.

```yaml
transformers:
  - name: url
    params: { template: "https://crm.example.com/contact/{value}" }
```

Example with value `"42"`: → `"https://crm.example.com/contact/42"`

The template supports `${ENV_VAR}` placeholders (resolved at config load time), so you can use instance-specific URLs:

```yaml
transformers:
  - name: url
    params: { template: "https://app.raynet.cz/${RAYNET_INSTANCE_NAME}/?view=DetailView&en=Person&ei={value}" }
```

### `join`
Joins an array value into a string. Filters out null and empty values before joining.

```yaml
transformers:
  - name: join
    params: { separator: " " }   # default separator is a space
```

Example: `["John", "Doe"]` → `"John Doe"`. Strings are passed through unchanged.

## Combining Multiple Fields with Append

Use `append: true` to collect multiple source fields into an array, then `multi_value: join` to collapse it into a string. The `multi_value` strategy on append fields runs after all values are accumulated.

```yaml
# Map firstName + lastName → title (e.g. "Kristýna Kovandová")
- crm_field: firstName
  cc_field: title
  append: true

- crm_field: lastName
  cc_field: title
  append: true
  multi_value:
    strategy: join
    separator: " "
```

Without `multi_value`, the result would be an array `["Kristýna", "Kovandová"]`. With `multi_value: join`, it collapses to `"Kristýna Kovandová"`.

## Transformer Chains

Multiple transformers are applied in sequence:

```yaml
transformers:
  - name: default_value
    params: { value: "unknown" }
  - name: string_case
    params: { case: upper }
```

This first fills `null` values with `"unknown"`, then uppercases the result.

## Environment Variables in Mapping Files

Mapping YAML files support the same `${ENV_VAR}` syntax as `sync.yaml`. This is resolved at config load time by `EnvResolver`. Inline interpolation works too: `"prefix${VAR}suffix"`.

This is particularly useful for URL templates that contain instance-specific values:

```yaml
transformers:
  - name: url
    params: { template: "https://app.raynet.cz/${RAYNET_INSTANCE_NAME}/?view=DetailView&en=Person&ei={value}" }
```

At load time, `${RAYNET_INSTANCE_NAME}` is replaced with the environment variable value, while `{value}` is a transformer placeholder replaced at runtime.

## Custom Transformers

Implement `ValueTransformerInterface` and register it:

```php
use Daktela\CrmSync\Mapping\Transformer\ValueTransformerInterface;

class CurrencyTransformer implements ValueTransformerInterface
{
    public function getName(): string { return 'currency'; }

    public function transform(mixed $value, array $params = []): mixed
    {
        $from = $params['from'] ?? 'CZK';
        $to = $params['to'] ?? 'EUR';
        // your conversion logic
        return $convertedValue;
    }
}

$registry = TransformerRegistry::withDefaults();
$registry->register(new CurrencyTransformer());

$engine = new SyncEngine($ccAdapter, $crmAdapter, $config, $logger, $registry);
```

```yaml
transformers:
  - name: currency
    params: { from: CZK, to: EUR }
```
