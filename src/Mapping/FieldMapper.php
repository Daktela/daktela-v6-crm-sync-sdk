<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping;

use Daktela\CrmSync\Entity\EntityInterface;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;

final class FieldMapper
{
    public function __construct(
        private readonly TransformerRegistry $registry,
    ) {
    }

    /**
     * Maps fields from a source entity to target data array based on mappings and direction.
     *
     * For CrmToCc: source=CRM field (target in YAML), target=CC field (source in YAML)
     * For CcToCrm: source=CC field (source in YAML), target=CRM field (target in YAML)
     *
     * @param array<string, array<string, string>> $relationMaps Keyed by entity type,
     *   each containing a map of source values to resolved target values.
     *   Example: ['account' => ['crm-acc-1' => 'acme', 'crm-acc-2' => 'globex']]
     * @return array<string, mixed>
     */
    public function map(
        EntityInterface $entity,
        MappingCollection $collection,
        SyncDirection $direction,
        array $relationMaps = [],
    ): array {
        $filtered = $collection->forDirection($direction);
        $result = [];

        foreach ($filtered->mappings as $mapping) {
            // In YAML: source = CC field, target = CRM field
            // CrmToCc: read from CRM (target), write to CC (source)
            // CcToCrm: read from CC (source), write to CRM (target)
            if ($direction === SyncDirection::CrmToCc) {
                $readField = $mapping->target;
                $writeField = $mapping->source;
            } else {
                $readField = $mapping->source;
                $writeField = $mapping->target;
            }

            $value = $this->readNestedValue($entity, $readField);
            $value = $this->applyTransformers($value, $mapping->transformers);

            // Apply relation resolution if configured
            if ($mapping->relation !== null && is_string($value) && $value !== '') {
                $value = $this->resolveRelation($value, $mapping->relation, $relationMaps);
            }

            // Apply multi-value strategy if configured
            if ($mapping->multiValue !== null) {
                $value = $mapping->multiValue->apply($value);
            }

            $this->setNestedValue($result, $writeField, $value);
        }

        return $result;
    }

    private function readNestedValue(EntityInterface $entity, string $field): mixed
    {
        if (!str_contains($field, '.')) {
            return $entity->get($field);
        }

        $parts = explode('.', $field);
        $value = $entity->get($parts[0]);

        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            if (!is_array($value) || !array_key_exists($parts[$i], $value)) {
                return null;
            }
            $value = $value[$parts[$i]];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function setNestedValue(array &$result, string $field, mixed $value): void
    {
        if (!str_contains($field, '.')) {
            $result[$field] = $value;
            return;
        }

        $parts = explode('.', $field);
        $current = &$result;

        for ($i = 0, $last = count($parts) - 1; $i < $last; $i++) {
            if (!isset($current[$parts[$i]]) || !is_array($current[$parts[$i]])) {
                $current[$parts[$i]] = [];
            }
            $current = &$current[$parts[$i]];
        }

        $current[$parts[array_key_last($parts)]] = $value;
    }

    /**
     * @param array<array{name: string, params: array<string, mixed>}> $transformers
     */
    private function applyTransformers(mixed $value, array $transformers): mixed
    {
        foreach ($transformers as $config) {
            $transformer = $this->registry->get($config['name']);
            $value = $transformer->transform($value, $config['params']);
        }

        return $value;
    }

    /**
     * @param array<string, array<string, string>> $relationMaps
     */
    private function resolveRelation(
        string $value,
        RelationConfig $relation,
        array $relationMaps,
    ): string {
        $map = $relationMaps[$relation->entity] ?? [];

        return $map[$value] ?? $value;
    }
}
