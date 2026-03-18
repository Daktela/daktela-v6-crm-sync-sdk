<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Exception;

class NotSupportedException extends AdapterException
{
    public static function activityNotSupported(string $adapterName): self
    {
        return new self(
            sprintf('%s adapter does not support activity operations', $adapterName),
        );
    }

    public static function operationNotSupported(string $adapterName, string $operation): self
    {
        return new self(
            sprintf('%s adapter does not support %s', $adapterName, $operation),
        );
    }
}
