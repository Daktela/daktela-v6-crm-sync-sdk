<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

final class StringCaseTransformer implements ValueTransformerInterface
{
    public function getName(): string
    {
        return 'string_case';
    }

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed
    {
        if ($value === null || !is_string($value)) {
            return $value;
        }

        $case = (string) ($params['case'] ?? 'lower');

        return match ($case) {
            'lower' => mb_strtolower($value),
            'upper' => mb_strtoupper($value),
            'title' => mb_convert_case($value, MB_CASE_TITLE),
            default => $value,
        };
    }
}
