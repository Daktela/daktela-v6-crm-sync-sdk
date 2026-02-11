<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

use Daktela\CrmSync\Sync\SyncDirection;

final readonly class FieldMapping
{
    /**
     * @param array<array{name: string, params: array<string, mixed>}> $transformers
     */
    public function __construct(
        public string $source,
        public string $target,
        public SyncDirection $direction,
        public array $transformers = [],
        public ?MultiValueConfig $multiValue = null,
        public ?RelationConfig $relation = null,
    ) {
    }
}
