<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

final class NestedValue
{
    /**
     * Resolves a dot-notation field path in a nested array.
     *
     * @param array<string, mixed> $data
     */
    public static function get(array $data, string $field): mixed
    {
        if (!str_contains($field, '.')) {
            return $data[$field] ?? null;
        }

        $parts = explode('.', $field);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }
}
