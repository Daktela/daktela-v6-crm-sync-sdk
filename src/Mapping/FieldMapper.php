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
        $result = [];

        // multi_value strategies deferred until after all append values are collected
        /** @var array<string, MultiValueConfig> */
        $deferredMultiValue = [];

        foreach ($collection->mappings as $mapping) {
            // CrmToCc: read from CRM field, write to CC field
            // CcToCrm: read from CC field, write to CRM field
            if ($direction === SyncDirection::CrmToCc) {
                $readField = $mapping->crmField;
                $writeField = $mapping->ccField;
            } else {
                $readField = $mapping->ccField;
                $writeField = $mapping->crmField;
            }

            $value = $this->readNestedValue($entity, $readField);
            $value = $this->applyTransformers($value, $mapping->transformers);

            // Apply relation resolution if configured
            if ($mapping->relation !== null && is_string($value) && $value !== '') {
                $value = $this->resolveRelation($value, $mapping->relation, $relationMaps);
            }

            if ($mapping->append) {
                // For append fields, defer multi_value to post-processing so it
                // runs on the final accumulated array, not on each individual value.
                if ($mapping->multiValue !== null) {
                    $deferredMultiValue[$writeField] = $mapping->multiValue;
                }
                $this->appendNestedValue($result, $writeField, $value);
            } else {
                // Apply multi-value strategy if configured
                if ($mapping->multiValue !== null) {
                    $value = $mapping->multiValue->apply($value);
                }
                $this->setNestedValue($result, $writeField, $value);
            }
        }

        // Collapse accumulated append fields (e.g. join ["John", "Doe"] → "John Doe")
        foreach ($deferredMultiValue as $field => $multiValue) {
            $accumulated = $this->getNestedValue($result, $field);
            if ($accumulated !== null) {
                $this->setNestedValue($result, $field, $multiValue->apply($accumulated));
            }
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
     * @param array<string, mixed> $result
     */
    private function appendNestedValue(array &$result, string $field, mixed $value): void
    {
        $existing = $this->getNestedValue($result, $field);

        $existingArray = is_array($existing) ? $existing : ($existing !== null ? [$existing] : []);
        $newArray = is_array($value) ? $value : ($value !== null && $value !== '' ? [$value] : []);

        $this->setNestedValue($result, $field, array_merge($existingArray, $newArray));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getNestedValue(array $data, string $field): mixed
    {
        if (!str_contains($field, '.')) {
            return $data[$field] ?? null;
        }

        $parts = explode('.', $field);
        $current = $data;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
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
