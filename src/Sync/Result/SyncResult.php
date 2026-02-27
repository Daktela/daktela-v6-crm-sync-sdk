<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync\Result;

final class SyncResult
{
    /** @var RecordResult[] */
    private array $records = [];

    private float $startTime;

    private float $endTime = 0;

    private bool $exhausted = true;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function addRecord(RecordResult $record): void
    {
        $this->records[] = $record;
    }

    public function finish(): void
    {
        $this->endTime = microtime(true);
    }

    /** @return RecordResult[] */
    public function getRecords(): array
    {
        return $this->records;
    }

    /** @return RecordResult[] */
    public function getFailedRecords(): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (RecordResult $r): bool => $r->status === SyncStatus::Failed,
        ));
    }

    public function getCreatedCount(): int
    {
        return $this->countByStatus(SyncStatus::Created);
    }

    public function getUpdatedCount(): int
    {
        return $this->countByStatus(SyncStatus::Updated);
    }

    public function getSkippedCount(): int
    {
        return $this->countByStatus(SyncStatus::Skipped);
    }

    public function getFailedCount(): int
    {
        return $this->countByStatus(SyncStatus::Failed);
    }

    public function getTotalCount(): int
    {
        return count($this->records);
    }

    public function getDuration(): float
    {
        $end = $this->endTime > 0 ? $this->endTime : microtime(true);

        return $end - $this->startTime;
    }

    public function setExhausted(bool $exhausted): void
    {
        $this->exhausted = $exhausted;
    }

    public function isExhausted(): bool
    {
        return $this->exhausted;
    }

    public function merge(self $other): void
    {
        array_push($this->records, ...$other->records);
        $this->startTime = min($this->startTime, $other->startTime);
        if ($other->endTime > 0) {
            $this->endTime = max($this->endTime, $other->endTime);
        }
    }

    public function getSummary(string $label): string
    {
        return sprintf(
            '%s: %d total, %d created, %d updated, %d skipped, %d failed (%.2fs)',
            $label,
            $this->getTotalCount(),
            $this->getCreatedCount(),
            $this->getUpdatedCount(),
            $this->getSkippedCount(),
            $this->getFailedCount(),
            $this->getDuration(),
        );
    }

    private function countByStatus(SyncStatus $status): int
    {
        return count(array_filter(
            $this->records,
            static fn (RecordResult $r): bool => $r->status === $status,
        ));
    }
}
