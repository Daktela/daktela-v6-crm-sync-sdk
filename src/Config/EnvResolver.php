<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

final class EnvResolver
{
    /**
     * Recursively resolves ${ENV_VAR} placeholders in an array.
     * Supports both whole-value ("${FOO}") and inline ("prefix${FOO}suffix") patterns.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function resolve(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = self::resolve($value);
            } elseif (is_string($value) && str_contains($value, '${')) {
                $result[$key] = preg_replace_callback(
                    '/\$\{(\w+)\}/',
                    static fn (array $m) => getenv($m[1]) ?: $m[0],
                    $value,
                );
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
