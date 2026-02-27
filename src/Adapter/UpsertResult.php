<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Adapter;

use Daktela\CrmSync\Entity\EntityInterface;

final readonly class UpsertResult
{
    public function __construct(
        public EntityInterface $entity,
        public bool $skipped = false,
    ) {
    }
}
