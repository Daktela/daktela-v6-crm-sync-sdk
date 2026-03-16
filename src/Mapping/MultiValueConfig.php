<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

final readonly class MultiValueConfig
{
    public function __construct(
        public MultiValueStrategy $strategy,
        public string $separator = ',',
    ) {
    }

    public function apply(mixed $value): mixed
    {
        return match ($this->strategy) {
            MultiValueStrategy::AsArray => $this->toArray($value),
            MultiValueStrategy::Join => $this->join($value),
            MultiValueStrategy::Split => $this->split($value),
            MultiValueStrategy::First => $this->first($value),
            MultiValueStrategy::Last => $this->last($value),
        };
    }

    private function toArray(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        return [$value];
    }

    private function join(mixed $value): string
    {
        if (is_array($value)) {
            return implode($this->separator, array_map(strval(...), $value));
        }

        return (string) ($value ?? '');
    }

    /** @return array<int, string> */
    private function split(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        $separator = $this->separator !== '' ? $this->separator : ',';

        return array_map(trim(...), explode($separator, (string) $value));
    }

    private function first(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value === [] ? null : reset($value);
        }

        return $value;
    }

    private function last(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value === [] ? null : end($value);
        }

        return $value;
    }
}
