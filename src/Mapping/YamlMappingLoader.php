<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

use Daktela\CrmSync\Config\EnvResolver;
use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Sync\SyncDirection;
use Symfony\Component\Yaml\Yaml;

final class YamlMappingLoader
{
    public function load(string $filePath): MappingCollection
    {
        if (!is_file($filePath)) {
            throw ConfigurationException::fileNotFound($filePath);
        }

        /** @var mixed $data */
        $data = Yaml::parseFile($filePath);

        if (is_array($data)) {
            $data = EnvResolver::resolve($data);
        }

        if (!is_array($data)) {
            throw ConfigurationException::invalidMappingFile($filePath, 'File must contain a YAML mapping');
        }

        if (!isset($data['entity']) || !is_string($data['entity'])) {
            throw ConfigurationException::invalidMappingFile($filePath, 'Missing or invalid "entity" key');
        }

        if (!isset($data['lookup_field']) || !is_string($data['lookup_field'])) {
            throw ConfigurationException::invalidMappingFile($filePath, 'Missing or invalid "lookup_field" key');
        }

        if (!isset($data['mappings']) || !is_array($data['mappings'])) {
            throw ConfigurationException::invalidMappingFile($filePath, 'Missing or invalid "mappings" key');
        }

        $mappings = [];
        foreach ($data['mappings'] as $index => $item) {
            if (!is_array($item)) {
                throw ConfigurationException::invalidMappingFile(
                    $filePath,
                    sprintf('Mapping at index %d must be an array', $index),
                );
            }

            $mappings[] = $this->parseFieldMapping($filePath, $index, $item);
        }

        return new MappingCollection(
            entityType: $data['entity'],
            lookupField: $data['lookup_field'],
            mappings: $mappings,
        );
    }

    /**
     * @param int $index
     * @param array<string, mixed> $item
     */
    private function parseFieldMapping(string $filePath, int $index, array $item): FieldMapping
    {
        if (!isset($item['source']) || !is_string($item['source'])) {
            throw ConfigurationException::invalidMappingFile(
                $filePath,
                sprintf('Mapping at index %d: missing or invalid "source"', $index),
            );
        }

        if (!isset($item['target']) || !is_string($item['target'])) {
            throw ConfigurationException::invalidMappingFile(
                $filePath,
                sprintf('Mapping at index %d: missing or invalid "target"', $index),
            );
        }

        $directionStr = (string) ($item['direction'] ?? 'bidirectional');
        $direction = SyncDirection::tryFrom($directionStr);

        if ($direction === null) {
            throw ConfigurationException::invalidMappingFile(
                $filePath,
                sprintf('Mapping at index %d: invalid direction "%s"', $index, $directionStr),
            );
        }

        $transformers = [];
        if (isset($item['transformers']) && is_array($item['transformers'])) {
            foreach ($item['transformers'] as $t) {
                if (!is_array($t) || !isset($t['name'])) {
                    throw ConfigurationException::invalidMappingFile(
                        $filePath,
                        sprintf('Mapping at index %d: invalid transformer definition', $index),
                    );
                }
                $transformers[] = [
                    'name' => (string) $t['name'],
                    'params' => is_array($t['params'] ?? null) ? $t['params'] : [],
                ];
            }
        }

        $multiValue = null;
        if (isset($item['multi_value']) && is_array($item['multi_value'])) {
            $strategyStr = (string) ($item['multi_value']['strategy'] ?? '');
            $strategy = MultiValueStrategy::tryFrom($strategyStr);
            if ($strategy === null) {
                throw ConfigurationException::invalidMappingFile(
                    $filePath,
                    sprintf('Mapping at index %d: invalid multi_value strategy "%s"', $index, $strategyStr),
                );
            }
            $multiValue = new MultiValueConfig(
                strategy: $strategy,
                separator: (string) ($item['multi_value']['separator'] ?? ','),
            );
        }

        $relation = null;
        if (isset($item['relation']) && is_array($item['relation'])) {
            $entity = (string) ($item['relation']['entity'] ?? '');
            $resolveFrom = (string) ($item['relation']['resolve_from'] ?? '');
            $resolveTo = (string) ($item['relation']['resolve_to'] ?? '');
            if ($entity === '' || $resolveFrom === '' || $resolveTo === '') {
                throw ConfigurationException::invalidMappingFile(
                    $filePath,
                    sprintf('Mapping at index %d: relation requires entity, resolve_from, and resolve_to', $index),
                );
            }
            $relation = new RelationConfig(
                entity: $entity,
                resolveFrom: $resolveFrom,
                resolveTo: $resolveTo,
            );
        }

        return new FieldMapping(
            source: $item['source'],
            target: $item['target'],
            direction: $direction,
            transformers: $transformers,
            multiValue: $multiValue,
            relation: $relation,
        );
    }
}
