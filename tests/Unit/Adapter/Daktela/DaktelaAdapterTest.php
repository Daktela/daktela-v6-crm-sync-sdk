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
}
