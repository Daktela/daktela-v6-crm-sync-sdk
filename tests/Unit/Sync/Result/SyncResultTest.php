<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Sync\Result;

use Daktela\CrmSync\Sync\Result\RecordResult;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\Result\SyncStatus;
use PHPUnit\Framework\TestCase;

final class SyncResultTest extends TestCase
{
    public function testGetSummaryFormatsCorrectly(): void
    {
        $result = new SyncResult();
        $result->addRecord(new RecordResult('contact', 'src-1', 'tgt-1', SyncStatus::Created));
        $result->addRecord(new RecordResult('contact', 'src-2', 'tgt-2', SyncStatus::Created));
        $result->addRecord(new RecordResult('contact', 'src-3', 'tgt-3', SyncStatus::Updated));
        $result->addRecord(new RecordResult('contact', 'src-4', null, SyncStatus::Skipped));
        $result->addRecord(new RecordResult('contact', 'src-5', null, SyncStatus::Failed, 'API error'));
        $result->finish();

        $summary = $result->getSummary('Contacts');

        self::assertMatchesRegularExpression(
            '/^Contacts: 5 total, 2 created, 1 updated, 1 skipped, 1 failed \(\d+\.\d{2}s\)$/',
            $summary,
        );
    }

    public function testGetSummaryWithEmptyResult(): void
    {
        $result = new SyncResult();
        $result->finish();

        $summary = $result->getSummary('Accounts');

        self::assertMatchesRegularExpression(
            '/^Accounts: 0 total, 0 created, 0 updated, 0 skipped, 0 failed \(\d+\.\d{2}s\)$/',
            $summary,
        );
    }
}
