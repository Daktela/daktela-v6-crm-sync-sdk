<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync\Result;

final readonly class FullSyncResult
{
    public function __construct(
        public ?SyncResult $account = null,
        public ?SyncResult $autoContact = null,
        public ?SyncResult $contact = null,
        public ?SyncResult $activity = null,
    ) {
    }

    /**
     * @return array<string, SyncResult>
     */
    public function toArray(): array
    {
        $result = [];

        if ($this->account !== null) {
            $result['account'] = $this->account;
        }
        if ($this->autoContact !== null) {
            $result['auto_contact'] = $this->autoContact;
        }
        if ($this->contact !== null) {
            $result['contact'] = $this->contact;
        }
        if ($this->activity !== null) {
            $result['activity'] = $this->activity;
        }

        return $result;
    }
}
