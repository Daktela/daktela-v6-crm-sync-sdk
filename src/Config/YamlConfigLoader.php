<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\YamlMappingLoader;
use Daktela\CrmSync\Sync\SyncDirection;
use Symfony\Component\Yaml\Yaml;

final class YamlConfigLoader
{
    public function __construct(
        private readonly YamlMappingLoader $mappingLoader = new YamlMappingLoader(),
    ) {
    }

    /**
     * Parses the YAML config file and resolves ${ENV_VAR} placeholders,
     * returning the full data array. Adapters can use this to read their
     * own sections (e.g., "raynet:", "salesforce:") from the shared config file.
     *
     * @return array<string, mixed>
     */
    public function loadRaw(string $configPath): array
    {
        if (!is_file($configPath)) {
            throw ConfigurationException::fileNotFound($configPath);
        }

        /** @var mixed $raw */
        $raw = Yaml::parseFile($configPath);

        if (!is_array($raw)) {
            throw ConfigurationException::invalidMappingFile($configPath, 'Config must be a YAML mapping');
        }

        return $this->resolveEnvVars($raw);
    }

    public function load(string $configPath): SyncConfiguration
    {
        $data = $this->loadRaw($configPath);
        $configDir = dirname($configPath);

        $instanceUrl = (string) ($data['daktela']['instance_url'] ?? '');
        $accessToken = (string) ($data['daktela']['access_token'] ?? '');
        $database = (string) ($data['daktela']['database'] ?? '');
        $batchSize = (int) ($data['sync']['batch_size'] ?? 100);
        $webhookSecret = (string) ($data['webhook']['secret'] ?? '');

        $entities = [];
        $mappings = [];
        $autoCreateContactMappings = [];

        $entityConfigs = $data['sync']['entities'] ?? [];
        if (is_array($entityConfigs)) {
            foreach ($entityConfigs as $type => $config) {
                if (!is_array($config)) {
                    continue;
                }

                $direction = SyncDirection::tryFrom((string) ($config['direction'] ?? ''));
                if ($direction === null) {
                    throw ConfigurationException::invalidMappingFile(
                        $configPath,
                        sprintf('Invalid direction for entity "%s"', $type),
                    );
                }

                $activityTypes = [];
                if (isset($config['activity_types']) && is_array($config['activity_types'])) {
                    foreach ($config['activity_types'] as $at) {
                        $activityType = ActivityType::tryFrom((string) $at);
                        if ($activityType !== null) {
                            $activityTypes[] = $activityType;
                        }
                    }
                }

                $autoCreateContact = null;
                if (isset($config['auto_create_contact']) && is_array($config['auto_create_contact'])) {
                    $acFile = (string) ($config['auto_create_contact']['mapping_file'] ?? '');
                    $skipFields = (array) ($config['auto_create_contact']['skip_if_exists'] ?? []);
                    $skipMode = SkipIfExistsMode::tryFrom(
                        (string) ($config['auto_create_contact']['skip_if_exists_mode'] ?? ''),
                    ) ?? SkipIfExistsMode::All;
                    $skipIfEmpty = (array) ($config['auto_create_contact']['skip_if_empty'] ?? []);
                    $autoCreateContact = new AutoCreateContactConfig($acFile, $skipFields, $skipMode, $skipIfEmpty);

                    if ($acFile !== '') {
                        $autoCreateContactMappings[(string) $type] = $this->mappingLoader->load(
                            $configDir . '/' . $acFile,
                        );
                    }
                }

                $mappingFile = (string) ($config['mapping_file'] ?? '');

                $entities[(string) $type] = new EntitySyncConfig(
                    enabled: (bool) ($config['enabled'] ?? false),
                    direction: $direction,
                    mappingFile: $mappingFile,
                    activityTypes: $activityTypes,
                    autoCreateContact: $autoCreateContact,
                );

                if ($mappingFile !== '') {
                    $fullPath = $configDir . '/' . $mappingFile;
                    $mappings[(string) $type] = $this->mappingLoader->load($fullPath);
                }
            }
        }

        return new SyncConfiguration(
            instanceUrl: $instanceUrl,
            accessToken: $accessToken,
            database: $database,
            batchSize: $batchSize,
            entities: $entities,
            mappings: $mappings,
            webhookSecret: $webhookSecret,
            autoCreateContactMappings: $autoCreateContactMappings,
        );
    }

    /**
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private function resolveEnvVars(array $data): array
    {
        return EnvResolver::resolve($data);
    }
}
