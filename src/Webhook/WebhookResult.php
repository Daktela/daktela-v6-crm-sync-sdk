<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Webhook;

use Daktela\CrmSync\Sync\Result\SyncResult;

final readonly class WebhookResult
{
    public function __construct(
        public SyncResult $syncResult,
        public int $httpStatusCode,
    ) {
    }

    /** @return array<string, mixed> */
    public function toResponseArray(): array
    {
        return [
            'status' => $this->httpStatusCode < 400 ? 'ok' : 'error',
            'total' => $this->syncResult->getTotalCount(),
            'created' => $this->syncResult->getCreatedCount(),
            'updated' => $this->syncResult->getUpdatedCount(),
            'skipped' => $this->syncResult->getSkippedCount(),
            'failed' => $this->syncResult->getFailedCount(),
            'duration' => round($this->syncResult->getDuration(), 3),
            'errors' => array_map(
                static fn ($r) => [
                    'source_id' => $r->sourceId,
                    'message' => $r->errorMessage,
                ],
                $this->syncResult->getFailedRecords(),
            ),
        ];
    }
}
