<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

class ConfigurationException extends SyncException
{
    public static function fileNotFound(string $path): self
    {
        return new self(
            sprintf('Configuration file not found: "%s"', $path),
        );
    }

    public static function invalidMappingFile(string $path, string $reason): self
    {
        return new self(
            sprintf('Invalid mapping file "%s": %s', $path, $reason),
        );
    }
}
