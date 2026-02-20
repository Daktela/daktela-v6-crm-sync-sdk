# Implementing a CRM Adapter

The SDK requires you to implement `CrmAdapterInterface` for your specific CRM system.

## Interface

```php
interface CrmAdapterInterface
{
    // Contacts (read-only — CRM is source-of-truth)
    public function findContact(string $id): ?Contact;
    public function findContactByLookup(string $field, string $value): ?Contact;
    public function iterateContacts(): \Generator;

    // Accounts (read-only — CRM is source-of-truth)
    public function findAccount(string $id): ?Account;
    public function findAccountByLookup(string $field, string $value): ?Account;
    public function iterateAccounts(): \Generator;

    // Activities (writable — CC is source-of-truth, CRM receives data)
    public function findActivity(string $id): ?Activity;
    public function findActivityByLookup(string $field, string $value): ?Activity;
    public function createActivity(Activity $activity): Activity;
    public function updateActivity(string $id, Activity $activity): Activity;
    public function upsertActivity(string $lookupField, Activity $activity): Activity;

    public function ping(): bool;
}
```

## Method Guide

### Read-Only Methods (Contacts & Accounts)

| Method | Purpose | When called |
|--------|---------|-------------|
| `findContact($id)` | Find by CRM ID | Webhook sync |
| `findContactByLookup($field, $value)` | Find by field value | Lookup operations |
| `iterateContacts()` | Iterate all contacts | Batch sync |
| `findAccount($id)` | Find by CRM ID | Webhook sync |
| `findAccountByLookup($field, $value)` | Find by field value | Lookup operations |
| `iterateAccounts()` | Iterate all accounts | Batch sync, relation map building |

### Write Methods (Activities)

| Method | Purpose | When called |
|--------|---------|-------------|
| `findActivity($id)` | Find by CRM ID | Before upsert |
| `findActivityByLookup($field, $value)` | Find by external ID | Upsert lookup |
| `createActivity($activity)` | Create new activity | Upsert (not found) |
| `updateActivity($id, $activity)` | Update existing | Upsert (found) |
| `upsertActivity($lookupField, $activity)` | Create or update | Batch/webhook sync |

### Connectivity

| Method | Purpose |
|--------|---------|
| `ping()` | Check CRM API connectivity |

## Example Implementation

```php
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Exception\AdapterException;

final class SalesforceCrmAdapter implements CrmAdapterInterface
{
    public function __construct(
        private readonly SalesforceClient $client,
    ) {}

    // --- Contacts (read-only) ---

    public function findContact(string $id): ?Contact
    {
        $record = $this->client->find('Contact', $id);
        return $record ? $this->mapContact($record) : null;
    }

    public function findContactByLookup(string $field, string $value): ?Contact
    {
        $record = $this->client->findBy('Contact', [$field => $value]);
        return $record ? $this->mapContact($record) : null;
    }

    public function iterateContacts(): \Generator
    {
        // Use generators for memory efficiency with large datasets
        $query = 'SELECT Id, Name, Email, Phone, AccountId FROM Contact';
        foreach ($this->client->queryAll($query) as $record) {
            yield $this->mapContact($record);
        }
    }

    // --- Accounts (read-only) ---

    public function findAccount(string $id): ?Account
    {
        $record = $this->client->find('Account', $id);
        return $record ? $this->mapAccount($record) : null;
    }

    public function findAccountByLookup(string $field, string $value): ?Account
    {
        $record = $this->client->findBy('Account', [$field => $value]);
        return $record ? $this->mapAccount($record) : null;
    }

    public function iterateAccounts(): \Generator
    {
        foreach ($this->client->queryAll('SELECT Id, Name, Industry FROM Account') as $record) {
            yield $this->mapAccount($record);
        }
    }

    // --- Activities (writable) ---

    public function findActivity(string $id): ?Activity
    {
        $record = $this->client->find('Task', $id);
        return $record ? Activity::fromArray($record) : null;
    }

    public function findActivityByLookup(string $field, string $value): ?Activity
    {
        $record = $this->client->findBy('Task', [$field => $value]);
        return $record ? Activity::fromArray($record) : null;
    }

    public function createActivity(Activity $activity): Activity
    {
        try {
            $id = $this->client->create('Task', $activity->toArray());
            return Activity::fromArray(array_merge($activity->toArray(), ['id' => $id]));
        } catch (\Throwable $e) {
            throw AdapterException::createFailed('activity', $e);
        }
    }

    public function updateActivity(string $id, Activity $activity): Activity
    {
        try {
            $this->client->update('Task', $id, $activity->toArray());
            return Activity::fromArray(array_merge($activity->toArray(), ['id' => $id]));
        } catch (\Throwable $e) {
            throw AdapterException::updateFailed('activity', $id, $e);
        }
    }

    public function upsertActivity(string $lookupField, Activity $activity): Activity
    {
        $lookupValue = $activity->get($lookupField);
        if ($lookupValue !== null) {
            $existing = $this->findActivityByLookup($lookupField, (string) $lookupValue);
            if ($existing !== null && $existing->getId() !== null) {
                return $this->updateActivity($existing->getId(), $activity);
            }
        }

        return $this->createActivity($activity);
    }

    public function ping(): bool
    {
        try {
            $this->client->getApiVersion();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // --- Mapping helpers ---

    private function mapContact(array $record): Contact
    {
        return Contact::fromArray([
            'id' => $record['Id'],
            'full_name' => $record['Name'],
            'email' => $record['Email'],
            'phone' => $record['Phone'],
            'company_id' => $record['AccountId'],
        ]);
    }

    private function mapAccount(array $record): Account
    {
        return Account::fromArray([
            'id' => $record['Id'],
            'company_name' => $record['Name'],
            'external_id' => $record['ExternalId__c'] ?? $record['Id'],
            'industry' => $record['Industry'],
        ]);
    }
}
```

## Key Principles

1. **Contacts and Accounts are read-only** — The CRM is the source of truth
2. **Activities are writable** — Daktela pushes activity data to the CRM
3. **Use generators for iteration** — `iterateContacts()` and `iterateAccounts()` should use `yield` for memory efficiency with large datasets
4. **Entity IDs are strings** — Map your CRM's native ID type to string
5. **Throw `AdapterException`** for failures — The sync engine catches these per-record
6. **Include all fields the mapping needs** — Check your YAML mapping to know which CRM fields to include

## Account Relationship

When your contacts reference accounts, make sure `iterateAccounts()` includes the fields needed for relation resolution:

```yaml
# contacts.yaml
- cc_field: account
  crm_field: company_id       # Your adapter must provide this field
  relation:
    entity: account
    resolve_from: id           # Your adapter's Account must have this field
    resolve_to: name           # Maps to Daktela account name
```

Your `iterateAccounts()` must yield accounts that include both the `id` and the CRM-side field that maps to the Daktela `name` (typically `external_id`).

## Tips from Production

Lessons learned from implementing CRM adapters against real APIs:

### Activity Type → Endpoint Mapping

Different CRMs expose activity types (calls, emails, meetings) as separate endpoints rather than a single unified resource. Your adapter's `createActivity()` / `updateActivity()` will need to route to the correct endpoint based on the activity type. Keep a mapping in your adapter:

```php
private function getActivityEndpoint(string $type): string
{
    return match ($type) {
        'call' => 'phoneCall/',
        'email' => 'email/',
        'meeting' => 'meeting/',
        default => throw AdapterException::createFailed('activity', "Unknown type: {$type}"),
    };
}
```

### Owner / User Resolution

CRM APIs often require an owner (user) ID when creating activities, but Daktela stores user info as nested objects with login, display name, and email. Common resolution strategies:

- **By email**: Match `user.email` or `user.emailAuth` to a CRM user — most reliable
- **By name**: Match `user.title` (display name) — less reliable (duplicates possible)
- **Fallback owner**: Configure a default owner ID for unresolved users

### External ID Prefix Strategy

When syncing between systems, prefix external IDs to avoid collisions with IDs from other integrations. For example, prefix Daktela contact names with `raynet_` before storing as external IDs in the CRM, and strip the prefix when reading back:

```yaml
# Contacts: CRM → Daktela
- cc_field: name
  crm_field: id
  transformers:
    - name: prefix
      params: { value: "raynet_person_" }

# Activities: Daktela → CRM (strip prefix to get raw CRM ID)
- cc_field: contact_name
  crm_field: contactPersonId
  transformers:
    - name: strip_prefix
      params: { value: "raynet_person_" }
```

### Company / Relation Resolution for Activities

Activities in Daktela reference contacts by their `name` field (which you set during contact sync). When syncing activities to the CRM, you need to resolve the Daktela contact name back to the CRM's person/contact ID. Use the `strip_prefix` transformer to extract the original CRM ID, then use it to link the activity to the correct person in the CRM.
