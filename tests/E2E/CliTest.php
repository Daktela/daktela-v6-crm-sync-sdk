<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\E2E;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end tests for the two shipped CLI binaries. Shells out via proc_open
 * and asserts exit code + stdout/stderr. Covers the happy path (--help and a
 * valid config) plus the most common failure modes for each bin. The bins are
 * ~1000 lines of procedural script so unit testing isn't viable.
 */
final class CliTest extends TestCase
{
    private const PROJECT_ROOT_REL = '..' . DIRECTORY_SEPARATOR . '..';

    private static function projectRoot(): string
    {
        return realpath(__DIR__ . DIRECTORY_SEPARATOR . self::PROJECT_ROOT_REL) ?: __DIR__;
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string, 2: string}
     */
    private static function runBin(string $bin, array $args): array
    {
        $root = self::projectRoot();
        $cmd = array_merge(['php', $root . '/bin/' . $bin], $args);

        $process = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            self::fail('Failed to spawn ' . $bin);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, $stdout, $stderr];
    }

    public function testConfigViewHelpExitsZero(): void
    {
        [$exit, $stdout] = self::runBin('config-view', ['--help']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Usage: bin/config-view', $stdout);
    }

    public function testConfigViewMissingConfigFails(): void
    {
        [$exit, , $stderr] = self::runBin('config-view', ['--config', '/does/not/exist.yaml']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('Config file not found', $stderr);
    }

    public function testConfigViewRendersValidConfig(): void
    {
        [$exit, $stdout] = self::runBin('config-view', [
            '--config', 'tests/Fixtures/config/sync.yaml',
        ]);

        self::assertSame(0, $exit, 'stdout: ' . $stdout);
        self::assertStringContainsString('Sync Configuration', $stdout);
        self::assertStringContainsString('contact', $stdout);
        self::assertStringContainsString('account', $stdout);
    }

    public function testConfigViewJsonOutput(): void
    {
        [$exit, $stdout] = self::runBin('config-view', [
            '--config', 'tests/Fixtures/config/sync.yaml',
            '--json',
        ]);

        self::assertSame(0, $exit);
        $parsed = json_decode($stdout, true);
        self::assertIsArray($parsed, 'config-view --json produced non-JSON output: ' . $stdout);
    }

    public function testCrmInspectHelpExitsZero(): void
    {
        [$exit, $stdout] = self::runBin('crm-inspect', ['--help']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('Usage: bin/crm-inspect', $stdout);
    }

    public function testCrmInspectMissingBootstrapFails(): void
    {
        [$exit, , $stderr] = self::runBin('crm-inspect', ['list', 'contact']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('Bootstrap file not found', $stderr);
    }

    public function testCrmInspectUnknownCommandFails(): void
    {
        [$exit, , $stderr] = self::runBin('crm-inspect', [
            '--bootstrap', 'tests/E2E/Fixtures/bootstrap.php',
            'notacommand',
        ]);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('Unknown command', $stderr);
    }

    public function testCrmInspectListHappyPath(): void
    {
        [$exit, $stdout] = self::runBin('crm-inspect', [
            '--bootstrap', 'tests/E2E/Fixtures/bootstrap.php',
            'list', 'contact',
        ]);

        self::assertSame(0, $exit, 'exit ' . $exit);
        self::assertStringContainsString('alice@acme.com', $stdout);
        self::assertStringContainsString('crm-c-1', $stdout);
    }
}
