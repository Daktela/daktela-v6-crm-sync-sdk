<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping\Transformer;

use Daktela\CrmSync\Mapping\Transformer\DateFormatTransformer;
use PHPUnit\Framework\TestCase;

final class DateFormatTransformerTest extends TestCase
{
    private DateFormatTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new DateFormatTransformer();
    }

    public function testGetName(): void
    {
        self::assertSame('date_format', $this->transformer->getName());
    }

    public function testTransformWithExplicitFormats(): void
    {
        $result = $this->transformer->transform('2025-01-15 14:30:00', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'Y-m-d',
        ]);

        self::assertSame('2025-01-15', $result);
    }

    public function testTransformToIsoFormat(): void
    {
        $result = $this->transformer->transform('2025-06-01 10:00:00', [
            'from' => 'Y-m-d H:i:s',
            'to' => 'c',
        ]);

        self::assertStringContainsString('2025-06-01', (string) $result);
    }

    public function testNullReturnsNull(): void
    {
        self::assertNull($this->transformer->transform(null));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        self::assertSame('', $this->transformer->transform(''));
    }

    public function testFallbackToGenericParsing(): void
    {
        $result = $this->transformer->transform('January 15, 2025', [
            'from' => 'Y/m/d',
            'to' => 'Y-m-d',
        ]);

        self::assertSame('2025-01-15', $result);
    }
}
