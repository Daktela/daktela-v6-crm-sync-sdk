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

    /** @var array<string, int> Counters accumulated via mergeCounts() */
    private array $counters = [
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'failed' => 0,
        'total' => 0,
    ];

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
        return $this->countByStatus(SyncStatus::Created) + $this->counters['created'];
    }

    public function getUpdatedCount(): int
    {
        return $this->countByStatus(SyncStatus::Updated) + $this->counters['updated'];
    }

    public function getSkippedCount(): int
    {
        return $this->countByStatus(SyncStatus::Skipped) + $this->counters['skipped'];
    }

    public function getFailedCount(): int
    {
        return $this->countByStatus(SyncStatus::Failed) + $this->counters['failed'];
    }

    public function getTotalCount(): int
    {
        return count($this->records) + $this->counters['total'];
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

    /**
     * Merge only the counters from another result (no RecordResult objects copied).
     * Keeps memory bounded when accumulating across many batches.
     */
    public function mergeCounts(self $other): void
    {
        $this->counters['created'] += $other->getCreatedCount();
        $this->counters['updated'] += $other->getUpdatedCount();
        $this->counters['skipped'] += $other->getSkippedCount();
        $this->counters['failed'] += $other->getFailedCount();
        $this->counters['total'] += $other->getTotalCount();
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
