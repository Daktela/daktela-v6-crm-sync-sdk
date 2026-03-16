<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Logging;

use Daktela\CrmSync\Logging\StderrLogger;
use PHPUnit\Framework\TestCase;

final class StderrLoggerTest extends TestCase
{
    public function testLogWritesFormattedMessage(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StderrLogger($stream);

        $logger->info('Test message');

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertMatchesRegularExpression(
            '/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\] INFO: Test message\n$/',
            $output,
        );
    }

    public function testLogInterpolatesPlaceholders(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StderrLogger($stream);

        $logger->error('User {name} failed with code {code}', ['name' => 'John', 'code' => 500]);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('ERROR: User John failed with code 500', $output);
    }

    public function testLogHandlesStringableContext(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StderrLogger($stream);

        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'stringable-value';
            }
        };

        $logger->warning('Value: {val}', ['val' => $stringable]);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('WARNING: Value: stringable-value', $output);
    }

    public function testLogHandlesDateTimeContext(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StderrLogger($stream);

        $date = new \DateTimeImmutable('2026-01-15T10:30:00+00:00');

        $logger->info('At {date}', ['date' => $date]);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('INFO: At 2026-01-15T10:30:00+00:00', $output);
    }

    public function testLogHandlesNullContext(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StderrLogger($stream);

        $logger->info('Value: [{val}]', ['val' => null]);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('INFO: Value: []', $output);
    }

    public function testLogHandlesNonStringableContext(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StderrLogger($stream);

        $logger->info('Data: {obj}', ['obj' => ['array']]);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('INFO: Data: [array]', $output);
    }

    public function testLogLevelUppercased(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StderrLogger($stream);

        $logger->debug('debug message');

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        self::assertStringContainsString('DEBUG: debug message', $output);
    }
}
