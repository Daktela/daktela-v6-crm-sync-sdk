<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Entity;

interface EntityInterface
{
    public function getId(): ?string;

    public function getType(): string;

    /** @return array<string, mixed> */
    public function getData(): array;

    public function get(string $field): mixed;

    public function set(string $field, mixed $value): void;

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
