<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping\Transformer;

use Daktela\CrmSync\Mapping\Transformer\PhoneNormalizeTransformer;
use PHPUnit\Framework\TestCase;

final class PhoneNormalizeTransformerTest extends TestCase
{
    private PhoneNormalizeTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new PhoneNormalizeTransformer();
    }

    public function testGetName(): void
    {
        self::assertSame('phone_normalize', $this->transformer->getName());
    }

    public function testNormalizeE164WithPlus(): void
    {
        $result = $this->transformer->transform('+420 123 456 789', ['format' => 'e164']);

        self::assertSame('+420123456789', $result);
    }

    public function testNormalizeE164WithoutPlus(): void
    {
        $result = $this->transformer->transform('420123456789', ['format' => 'e164']);

        self::assertSame('+420123456789', $result);
    }

    public function testNormalizeWithBracketsAndDashes(): void
    {
        $result = $this->transformer->transform('(420) 123-456-789', ['format' => 'e164']);

        self::assertSame('+420123456789', $result);
    }

    public function testNullReturnsNull(): void
    {
        self::assertNull($this->transformer->transform(null));
    }

    public function testEmptyReturnsEmpty(): void
    {
        self::assertSame('', $this->transformer->transform(''));
    }
}
