<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping\Transformer;

use Daktela\CrmSync\Mapping\Transformer\CallbackTransformer;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use PHPUnit\Framework\TestCase;

final class TransformerRegistryTest extends TestCase
{
    public function testWithDefaultsRegistersStrvalCallback(): void
    {
        $registry = TransformerRegistry::withDefaults();

        /** @var CallbackTransformer $callback */
        $callback = $registry->get('callback');

        self::assertSame('42', $callback->transform(42, ['name' => 'strval']));
        self::assertSame('', $callback->transform(null, ['name' => 'strval']));
        self::assertSame('hello', $callback->transform('hello', ['name' => 'strval']));
    }
}
