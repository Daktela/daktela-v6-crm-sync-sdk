<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class StripPrefixTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'strip_prefix';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null || !is_string($value) || $value === '') {
            return $value;
        }

        $prefix = (string) ($params['value'] ?? '');
        if ($prefix !== '' && str_starts_with($value, $prefix)) {
            return substr($value, strlen($prefix));
        }

        return $value;
    }
}
