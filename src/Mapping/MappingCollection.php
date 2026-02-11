<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

use Daktela\CrmSync\Sync\SyncDirection;

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

    public function forDirection(SyncDirection $direction): self
    {
        $filtered = array_values(array_filter(
            $this->mappings,
            static fn (FieldMapping $m): bool => $m->direction === $direction
                || $m->direction === SyncDirection::Bidirectional,
        ));

        return new self($this->entityType, $this->lookupField, $filtered);
    }
}
