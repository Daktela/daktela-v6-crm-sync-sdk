<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class BooleanTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'boolean';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): bool
    {
        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
