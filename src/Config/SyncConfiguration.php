<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Mapping\MappingCollection;

final class SyncConfiguration
{
    /**
     * @param array<string, EntitySyncConfig> $entities
     * @param array<string, MappingCollection> $mappings
     */
    public function __construct(
        public readonly string $instanceUrl,
        public readonly string $accessToken,
        public readonly int $batchSize,
        public readonly array $entities,
        public readonly array $mappings,
        public readonly string $webhookSecret = '',
    ) {
    }

    public function getEntityConfig(string $entityType): ?EntitySyncConfig
    {
        return $this->entities[$entityType] ?? null;
    }

    public function getMapping(string $entityType): ?MappingCollection
    {
        return $this->mappings[$entityType] ?? null;
    }

    public function isEntityEnabled(string $entityType): bool
    {
        $config = $this->getEntityConfig($entityType);

        return $config !== null && $config->enabled;
    }
}
