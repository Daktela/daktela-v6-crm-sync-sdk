<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Entity;

final class Activity implements EntityInterface
{
    /** @param array<string, mixed> $data */
    public function __construct(
        private ?string $id = null,
        private array $data = [],
        private ?ActivityType $activityType = null,
    ) {
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getType(): string
    {
        return 'activity';
    }

    public function getActivityType(): ?ActivityType
    {
        return $this->activityType;
    }

    public function setActivityType(ActivityType $activityType): void
    {
        $this->activityType = $activityType;
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data;
    }

    public function get(string $field): mixed
    {
        if ($field === 'id') {
            return $this->id;
        }

        return $this->data[$field] ?? null;
    }

    public function set(string $field, mixed $value): void
    {
        $this->data[$field] = $value;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        $id = isset($data['id']) ? (string) $data['id'] : null;
        $activityType = isset($data['activity_type'])
            ? ActivityType::from((string) $data['activity_type'])
            : null;
        unset($data['id'], $data['activity_type']);

        return new static($id, $data, $activityType);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $result = $this->data;
        if ($this->id !== null) {
            $result['id'] = $this->id;
        }
        if ($this->activityType !== null) {
            $result['activity_type'] = $this->activityType->value;
        }

        return $result;
    }
}
