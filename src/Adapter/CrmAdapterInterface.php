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
    public function iterateContacts(?\DateTimeImmutable $since = null): \Generator;

    // Accounts (read-only — CRM is source-of-truth)
    public function findAccount(string $id): ?Account;

    public function findAccountByLookup(string $field, string $value): ?Account;

    /** @return \Generator<int, Account> */
    public function iterateAccounts(?\DateTimeImmutable $since = null): \Generator;

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
}
