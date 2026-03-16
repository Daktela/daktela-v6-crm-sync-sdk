<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping\Transformer;

use Daktela\CrmSync\Mapping\Transformer\BooleanTransformer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BooleanTransformerTest extends TestCase
{
    private BooleanTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new BooleanTransformer();
    }

    public function testGetName(): void
    {
        self::assertSame('boolean', $this->transformer->getName());
    }

    #[DataProvider('truthyValues')]
    public function testTruthyValues(mixed $input): void
    {
        self::assertTrue($this->transformer->transform($input));
    }

    #[DataProvider('falsyValues')]
    public function testFalsyValues(mixed $input): void
    {
        self::assertFalse($this->transformer->transform($input));
    }

    /** @return array<string, array{mixed}> */
    public static function truthyValues(): array
    {
        return [
            'string true' => ['true'],
            'string TRUE' => ['TRUE'],
            'string 1' => ['1'],
            'string yes' => ['yes'],
            'string on' => ['on'],
            'int 1' => [1],
            'bool true' => [true],
        ];
    }

    /** @return array<string, array{mixed}> */
    public static function falsyValues(): array
    {
        return [
            'string false' => ['false'],
            'string 0' => ['0'],
            'string no' => ['no'],
            'string empty' => [''],
            'int 0' => [0],
            'bool false' => [false],
            'null' => [null],
        ];
    }
}
