<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Sync;

enum SyncDirection: string
{
    case CrmToCc = 'crm_to_cc';
    case CcToCrm = 'cc_to_crm';
    case Bidirectional = 'bidirectional';
}
