<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

final readonly class FieldMapping
{
    /**
     * @param array<array{name: string, params: array<string, mixed>}> $transformers
     */
    public function __construct(
        public string $ccField,
        public string $crmField,
        public array $transformers = [],
        public ?MultiValueConfig $multiValue = null,
        public ?RelationConfig $relation = null,
        public bool $append = false,
    ) {
    }
}
