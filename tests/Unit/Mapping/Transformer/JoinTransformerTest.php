<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping\Transformer;

use Daktela\CrmSync\Mapping\Transformer\JoinTransformer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JoinTransformerTest extends TestCase
{
    private JoinTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new JoinTransformer();
    }

    #[Test]
    public function itHasCorrectName(): void
    {
        self::assertSame('join', $this->transformer->getName());
    }

    #[Test]
    public function itJoinsArrayValues(): void
    {
        $result = $this->transformer->transform(['John', 'Doe']);

        self::assertSame('John Doe', $result);
    }

    #[Test]
    public function itFiltersEmptyStrings(): void
    {
        $result = $this->transformer->transform(['John', '']);

        self::assertSame('John', $result);
    }

    #[Test]
    public function itFiltersNullValues(): void
    {
        $result = $this->transformer->transform([null, 'Doe']);

        self::assertSame('Doe', $result);
    }

    #[Test]
    public function itPassesThroughStringValue(): void
    {
        self::assertSame('John Doe', $this->transformer->transform('John Doe'));
    }

    #[Test]
    public function itHandlesNullInput(): void
    {
        self::assertSame('', $this->transformer->transform(null));
    }

    #[Test]
    public function itUsesCustomSeparator(): void
    {
        $result = $this->transformer->transform(['John', 'Doe'], ['separator' => ', ']);

        self::assertSame('John, Doe', $result);
    }
}
