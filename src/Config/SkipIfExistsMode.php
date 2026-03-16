<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Config;

enum SkipIfExistsMode: string
{
    case All = 'all';
    case Any = 'any';
}
