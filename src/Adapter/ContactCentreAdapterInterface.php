<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Entity\Contact;

interface ContactCentreAdapterInterface
{
    // Contacts (writable — CRM is source-of-truth, CC receives data)
    public function findContact(string $id): ?Contact;

    /** @param array<string, mixed> $criteria */
    public function findContactBy(array $criteria): ?Contact;

    public function createContact(Contact $contact): Contact;

    public function updateContact(string $id, Contact $contact): Contact;

    public function upsertContact(string $lookupField, Contact $contact): Contact;

    // Accounts (writable — CRM is source-of-truth, CC receives data)
    public function findAccount(string $id): ?Account;

    /** @param array<string, mixed> $criteria */
    public function findAccountBy(array $criteria): ?Account;

    public function createAccount(Account $account): Account;

    public function updateAccount(string $id, Account $account): Account;

    public function upsertAccount(string $lookupField, Account $account): Account;

    // Activities (read-only — CC is source-of-truth)
    public function findActivity(string $id, ActivityType $type): ?Activity;

    /** @return \Generator<int, Activity> */
    public function iterateActivities(ActivityType $type, ?\DateTimeImmutable $since = null): \Generator;

    public function ping(): bool;
}
