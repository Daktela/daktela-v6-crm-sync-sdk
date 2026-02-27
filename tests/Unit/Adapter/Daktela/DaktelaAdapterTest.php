<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Adapter\Daktela;

use Daktela\CrmSync\Adapter\Daktela\DaktelaAdapter;
use Daktela\CrmSync\Entity\ActivityType;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DaktelaAdapterTest extends TestCase
{
    public function testAdapterCanBeInstantiated(): void
    {
        $adapter = new DaktelaAdapter(
            'https://test.daktela.com',
            'test-token',
            'test-db',
            new NullLogger(),
        );

        self::assertInstanceOf(DaktelaAdapter::class, $adapter);
    }

    public function testActivitiesModelConstant(): void
    {
        $adapter = new DaktelaAdapter(
            'https://test.daktela.com',
            'test-token',
            'test-db',
            new NullLogger(),
        );

        // All activity types use the single 'Activities' model endpoint
        $reflection = new \ReflectionClass($adapter);
        $constant = $reflection->getConstant('ACTIVITIES_MODEL');

        self::assertSame('Activities', $constant);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hasChangesProvider')]
    public function testHasChanges(array $existing, array $new, bool $expected): void
    {
        $adapter = new DaktelaAdapter(
            'https://test.daktela.com',
            'test-token',
            'test-db',
            new NullLogger(),
        );

        $method = new \ReflectionMethod($adapter, 'hasChanges');

        self::assertSame($expected, $method->invoke($adapter, $existing, $new));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>, bool}> */
    public static function hasChangesProvider(): iterable
    {
        yield 'identical data' => [
            ['title' => 'John', 'email' => 'john@test.com'],
            ['title' => 'John', 'email' => 'john@test.com'],
            false,
        ];

        yield 'changed field' => [
            ['title' => 'John', 'email' => 'john@test.com'],
            ['title' => 'Jane', 'email' => 'john@test.com'],
            true,
        ];

        yield 'phone with spaces vs stripped' => [
            ['phone' => '+420553401520'],
            ['phone' => '+420 553 401 520'],
            false,
        ];

        yield 'url wrapped in array by Daktela' => [
            ['web' => ['https://example.com']],
            ['web' => 'https://example.com'],
            false,
        ];

        yield 'url array vs different string' => [
            ['web' => ['https://example.com']],
            ['web' => 'https://other.com'],
            true,
        ];

        yield 'extra fields in existing are ignored' => [
            ['title' => 'John', 'database' => 'main', 'extra' => 'foo'],
            ['title' => 'John'],
            false,
        ];

        yield 'loose type comparison int vs string' => [
            ['priority' => 123],
            ['priority' => '123'],
            false,
        ];

        yield 'nested customFields with phone spaces and url array' => [
            ['customFields' => ['phone' => '+420553401520', 'web' => ['https://example.com']]],
            ['customFields' => ['phone' => '+420 553 401 520', 'web' => 'https://example.com']],
            false,
        ];

        yield 'nested customFields with actual change' => [
            ['customFields' => ['phone' => '+420553401520']],
            ['customFields' => ['phone' => '+421999888777']],
            true,
        ];

        yield 'stdClass from json_decode treated as array' => [
            ['customFields' => (object) ['phone' => '+420553401520', 'web' => ['https://example.com']]],
            ['customFields' => ['phone' => '+420 553 401 520', 'web' => 'https://example.com']],
            false,
        ];

        yield 'text field whitespace change is detected' => [
            ['title' => 'JohnDoe'],
            ['title' => 'John Doe'],
            true,
        ];

        yield 'text field extra whitespace is collapsed' => [
            ['title' => 'John Doe'],
            ['title' => '  John   Doe  '],
            false,
        ];
    }
}
