<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class PrefixTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'prefix';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $prefix = (string) ($params['value'] ?? '');

        return $prefix . $value;
    }
}
