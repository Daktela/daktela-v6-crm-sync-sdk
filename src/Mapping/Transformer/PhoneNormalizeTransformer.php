<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class PhoneNormalizeTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'phone_normalize';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $phone = (string) $value;
        // Strip all non-digit and non-plus characters
        $normalized = preg_replace('/[^\d+]/', '', $phone);

        if ($normalized === null || $normalized === '') {
            return $value;
        }

        $format = (string) ($params['format'] ?? 'e164');

        if ($format === 'e164' && !str_starts_with($normalized, '+')) {
            $normalized = '+' . $normalized;
        }

        return $normalized;
    }
}
