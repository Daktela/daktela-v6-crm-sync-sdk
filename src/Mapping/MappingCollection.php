<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

final readonly class MappingCollection
{
    /**
     * @param FieldMapping[] $mappings
     */
    public function __construct(
        public string $entityType,
        public string $lookupField,
        public array $mappings,
    ) {
    }
}
