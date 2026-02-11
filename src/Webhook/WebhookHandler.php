<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Webhook;

use Daktela\CrmSync\Entity\ActivityType;
use Daktela\CrmSync\Sync\Result\SyncResult;
use Daktela\CrmSync\Sync\SyncEngine;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class WebhookHandler
{
    public function __construct(
        private readonly SyncEngine $syncEngine,
        private readonly WebhookPayloadParser $parser,
        private readonly string $webhookSecret,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request): WebhookResult
    {
        if ($this->webhookSecret !== '' && !$this->validateSecret($request)) {
            $this->logger->warning('Webhook request with invalid secret');

            $result = new SyncResult();
            $result->finish();

            return new WebhookResult($result, 401);
        }

        $payload = $this->parser->parse($request);

        $this->logger->info('Webhook received: {event} for {type} {id}', [
            'event' => $payload->event,
            'type' => $payload->entityType,
            'id' => $payload->entityId,
        ]);

        try {
            $syncResult = $this->route($payload);

            $statusCode = $syncResult->getFailedCount() > 0 ? 207 : 200;

            return new WebhookResult($syncResult, $statusCode);
        } catch (\Throwable $e) {
            $this->logger->error('Webhook handling failed: {error}', [
                'error' => $e->getMessage(),
                'event' => $payload->event,
            ]);

            $result = new SyncResult();
            $result->finish();

            return new WebhookResult($result, 500);
        }
    }

    private function validateSecret(ServerRequestInterface $request): bool
    {
        $provided = $request->getHeaderLine('X-Webhook-Secret');

        return hash_equals($this->webhookSecret, $provided);
    }

    private function route(WebhookPayload $payload): SyncResult
    {
        return match ($payload->entityType) {
            'contact' => $this->syncEngine->syncContact($payload->entityId),
            'account' => $this->syncEngine->syncAccount($payload->entityId),
            'activity' => $this->syncEngine->syncActivity(
                $payload->entityId,
                $payload->activityType ?? ActivityType::Call,
            ),
            default => $this->emptyResult(),
        };
    }

    private function emptyResult(): SyncResult
    {
        $result = new SyncResult();
        $result->finish();

        return $result;
    }
}
