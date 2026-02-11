<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\State;

use Daktela\CrmSync\State\FileSyncStateStore;
use PHPUnit\Framework\TestCase;

final class FileSyncStateStoreTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/crm_sync_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        $file = $this->tempDir . '/state.json';
        if (file_exists($file)) {
            unlink($file);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testReturnsNullWhenFileDoesNotExist(): void
    {
        $store = new FileSyncStateStore($this->tempDir . '/state.json');

        self::assertNull($store->getLastSyncTime('contact'));
    }

    public function testStoresAndRetrievesTimestamp(): void
    {
        $store = new FileSyncStateStore($this->tempDir . '/state.json');
        $time = new \DateTimeImmutable('2025-06-15T10:30:00+02:00');

        $store->setLastSyncTime('contact', $time);
        $retrieved = $store->getLastSyncTime('contact');

        self::assertNotNull($retrieved);
        self::assertSame($time->format(\DateTimeInterface::ATOM), $retrieved->format(\DateTimeInterface::ATOM));
    }

    public function testMultipleEntityTypesStoredIndependently(): void
    {
        $store = new FileSyncStateStore($this->tempDir . '/state.json');
        $contactTime = new \DateTimeImmutable('2025-06-15T10:00:00+00:00');
        $accountTime = new \DateTimeImmutable('2025-06-15T11:00:00+00:00');

        $store->setLastSyncTime('contact', $contactTime);
        $store->setLastSyncTime('account', $accountTime);

        self::assertSame(
            $contactTime->format(\DateTimeInterface::ATOM),
            $store->getLastSyncTime('contact')?->format(\DateTimeInterface::ATOM),
        );
        self::assertSame(
            $accountTime->format(\DateTimeInterface::ATOM),
            $store->getLastSyncTime('account')?->format(\DateTimeInterface::ATOM),
        );
    }

    public function testClearRemovesOnlySpecifiedEntityType(): void
    {
        $store = new FileSyncStateStore($this->tempDir . '/state.json');
        $store->setLastSyncTime('contact', new \DateTimeImmutable());
        $store->setLastSyncTime('account', new \DateTimeImmutable());

        $store->clear('contact');

        self::assertNull($store->getLastSyncTime('contact'));
        self::assertNotNull($store->getLastSyncTime('account'));
    }

    public function testClearAllRemovesEverything(): void
    {
        $store = new FileSyncStateStore($this->tempDir . '/state.json');
        $store->setLastSyncTime('contact', new \DateTimeImmutable());
        $store->setLastSyncTime('account', new \DateTimeImmutable());

        $store->clearAll();

        self::assertNull($store->getLastSyncTime('contact'));
        self::assertNull($store->getLastSyncTime('account'));
    }

    public function testCorruptedJsonFileReturnsNull(): void
    {
        $filePath = $this->tempDir . '/state.json';
        mkdir($this->tempDir, 0o755, true);
        file_put_contents($filePath, 'not valid json {{{');

        $store = new FileSyncStateStore($filePath);

        self::assertNull($store->getLastSyncTime('contact'));
    }

    public function testTimestampPreservesTimezoneInfo(): void
    {
        $store = new FileSyncStateStore($this->tempDir . '/state.json');
        $time = new \DateTimeImmutable('2025-06-15T10:30:00+05:30');

        $store->setLastSyncTime('contact', $time);
        $retrieved = $store->getLastSyncTime('contact');

        self::assertNotNull($retrieved);
        self::assertSame('+05:30', $retrieved->format('P'));
    }
}
