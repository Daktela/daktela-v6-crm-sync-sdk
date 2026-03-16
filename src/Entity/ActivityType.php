<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Entity;

enum ActivityType: string
{
    case Call = 'call';
    case Email = 'email';
    case Chat = 'web';
    case Sms = 'sms';
    case Messenger = 'fbm';
    case WhatsApp = 'wap';
    case Viber = 'vbr';
}
