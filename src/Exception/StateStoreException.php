<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

class StateStoreException extends SyncException
{
    public static function readFailed(string $path, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to read state store file "%s"', $path),
            0,
            $previous,
        );
    }

    public static function writeFailed(string $path, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Failed to write state store file "%s"', $path),
            0,
            $previous,
        );
    }
}
