<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Mapping\Transformer;

interface ValueTransformerInterface
{
    public function getName(): string;

    /** @param array<string, mixed> $params */
    public function transform(mixed $value, array $params = []): mixed;
}
