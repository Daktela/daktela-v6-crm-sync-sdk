<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class DefaultValueTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'default_value';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null) {
            return $params['value'] ?? null;
        }

        return $value;
    }
}
