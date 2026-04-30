<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Mapping\MappingCollection;

final class SyncConfiguration
{
    /**
     * @param array<string, EntitySyncConfig> $entities
     * @param array<string, MappingCollection> $mappings
     * @param array<string, MappingCollection> $autoCreateContactMappings keyed by entity type
     * @param CustomEntitySyncConfig[] $customEntities
     * @param array<string, MappingCollection> $customEntityMappings keyed by CustomEntitySyncConfig::$name
     */
    public function __construct(
        public readonly string $instanceUrl,
        public readonly string $accessToken,
        public readonly string $database,
        public readonly int $batchSize,
        public readonly array $entities,
        public readonly array $mappings,
        public readonly string $webhookSecret = '',
        public readonly array $autoCreateContactMappings = [],
        public readonly array $customEntities = [],
        public readonly array $customEntityMappings = [],
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

    public function getAutoCreateContactMapping(string $entityType): ?MappingCollection
    {
        return $this->autoCreateContactMappings[$entityType] ?? null;
    }

    public function isEntityEnabled(string $entityType): bool
    {
        $config = $this->getEntityConfig($entityType);

        return $config !== null && $config->enabled;
    }

    /** @return CustomEntitySyncConfig[] */
    public function getEnabledCustomEntities(): array
    {
        return array_values(array_filter($this->customEntities, static fn (CustomEntitySyncConfig $c) => $c->enabled));
    }

    public function getCustomEntityMapping(string $name): ?MappingCollection
    {
        return $this->customEntityMappings[$name] ?? null;
    }
}
