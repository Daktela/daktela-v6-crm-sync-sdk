<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync\Result;

final readonly class RecordResult
{
    public function __construct(
        public string $entityType,
        public ?string $sourceId,
        public ?string $targetId,
        public SyncStatus $status,
        public string $errorMessage = '',
    ) {
    }
}
