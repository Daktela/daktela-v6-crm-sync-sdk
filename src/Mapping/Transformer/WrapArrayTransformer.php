<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class WrapArrayTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'wrap_array';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }
}
