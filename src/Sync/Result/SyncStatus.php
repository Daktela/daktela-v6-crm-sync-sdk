<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync\Result;

enum SyncStatus: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
