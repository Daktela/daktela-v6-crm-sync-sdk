<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Webhook;

use Daktela\CrmSync\Entity\ActivityType;

final readonly class WebhookPayload
{
    /**
     * @param array<string, mixed> $rawData
     */
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $event,
        public array $rawData,
        public ?ActivityType $activityType = null,
    ) {
    }
}
