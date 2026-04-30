<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\Contact;

interface CrmAdapterInterface
{
    // Contacts (read-only — CRM is source-of-truth)
    public function findContact(string $id): ?Contact;

    public function findContactByLookup(string $field, string $value): ?Contact;

    /** @return \Generator<int, Contact> */
    public function iterateContacts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator;

    // Accounts (read-only — CRM is source-of-truth)
    public function findAccount(string $id): ?Account;

    public function findAccountByLookup(string $field, string $value): ?Account;

    /** @return \Generator<int, Account> */
    public function iterateAccounts(?\DateTimeImmutable $since = null, int $offset = 0): \Generator;

    // Fulltext search
    /** @return \Generator<int, Contact> */
    public function searchContacts(string $query): \Generator;

    /** @return \Generator<int, Account> */
    public function searchAccounts(string $query): \Generator;

    // Activities (writable — CC is source-of-truth, CRM receives data)
    public function findActivity(string $id): ?Activity;

    public function findActivityByLookup(string $field, string $value): ?Activity;

    public function createActivity(Activity $activity): Activity;

    public function updateActivity(string $id, Activity $activity): Activity;

    public function upsertActivity(string $lookupField, Activity $activity): Activity;

    public function ping(): bool;

    // Custom entities (read-only) — generic by-name access to any CRM-side object the adapter supports.
    // Used by sync.custom_entities[] to feed records into a Daktela target (contact / account / activity).
    // Returned arrays are flat associative records; the mapping layer handles the rest.
    // Adapters that don't support a given $entityName should throw NotSupportedException.

    /** @return \Generator<int, array<string, mixed>> */
    public function iterateCustomEntity(string $entityName, ?\DateTimeImmutable $since = null, int $offset = 0): \Generator;

    /** @return array<string, mixed>|null */
    public function findCustomEntity(string $entityName, string $id): ?array;

    /** @return array<string, mixed>|null */
    public function findCustomEntityByLookup(string $entityName, string $field, string $value): ?array;
}
