<?php

declare(strict_types=1);

namespace Daktela\CrmSync\State;

interface SyncStateStoreInterface
{
    public function getLastSyncTime(string $entityType): ?\DateTimeImmutable;

    public function setLastSyncTime(string $entityType, \DateTimeImmutable $time): void;

    public function clear(string $entityType): void;

    public function clearAll(): void;
}
