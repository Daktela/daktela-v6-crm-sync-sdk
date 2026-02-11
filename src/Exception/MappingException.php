<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

class MappingException extends SyncException
{
    public static function unknownTransformer(string $name): self
    {
        return new self(
            sprintf('Unknown transformer "%s"', $name),
        );
    }
}
