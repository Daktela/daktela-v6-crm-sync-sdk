<?php

declare(strict_types=1);

// Bootstrap fixture for crm-inspect E2E tests. Returns a CrmAdapterInterface
// backed by the in-memory fake shared with the Integration suite.
use Daktela\CrmSync\Entity\Account;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Tests\Integration\Fakes\FakeCrmAdapter;

return new FakeCrmAdapter(
    contacts: [
        Contact::fromArray(['id' => 'crm-c-1', 'full_name' => 'Alice', 'email' => 'alice@acme.com']),
    ],
    accounts: [
        Account::fromArray(['id' => 'acc-1', 'company_name' => 'Acme', 'external_id' => 'acme']),
    ],
);
