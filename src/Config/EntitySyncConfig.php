<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Sync\SyncDirection;

final readonly class EntitySyncConfig
{
    /**
     * @param ActivityType[] $activityTypes
     */
    public function __construct(
        public bool $enabled,
        public SyncDirection $direction,
        public string $mappingFile,
        public array $activityTypes = [],
        public ?AutoCreateContactConfig $autoCreateContact = null,
    ) {
    }
}
