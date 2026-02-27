<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync\Result;

final readonly class AccountSyncResult
{
    public function __construct(
        public SyncResult $account,
        public SyncResult $autoContact,
    ) {
    }
}
