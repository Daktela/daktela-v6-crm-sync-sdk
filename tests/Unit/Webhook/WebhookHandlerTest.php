<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Webhook;

use Daktela\CrmSync\Adapter\ContactCentreAdapterInterface;
use Daktela\CrmSync\Adapter\CrmAdapterInterface;
use Daktela\CrmSync\Config\EntitySyncConfig;
use Daktela\CrmSync\Config\SyncConfiguration;
use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Sync\SyncDirection;
use Daktela\CrmSync\Sync\SyncEngine;
use Daktela\CrmSync\Webhook\WebhookHandler;
use Daktela\CrmSync\Webhook\WebhookPayloadParser;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;

final class WebhookHandlerTest extends TestCase
{
    public function testInvalidSecretReturns401(): void
    {
        $handler = $this->createHandler('my-secret');

        $request = $this->createRequest(
            '{"event": "contact_update", "name": "c-1"}',
            'application/json',
            'wrong-secret',
        );

        $result = $handler->handle($request);

        self::assertSame(401, $result->httpStatusCode);
    }

    public function testValidSecretProcessesRequest(): void
    {
        $handler = $this->createHandler('my-secret');

        $request = $this->createRequest(
            '{"event": "contact_update", "name": "crm-1"}',
            'application/json',
            'my-secret',
        );

        $result = $handler->handle($request);

        self::assertSame(200, $result->httpStatusCode);
    }

    public function testEmptySecretSkipsValidation(): void
    {
        $handler = $this->createHandler('');

        $request = $this->createRequest(
            '{"event": "contact_update", "name": "crm-1"}',
            'application/json',
            '',
        );

        $result = $handler->handle($request);

        self::assertSame(200, $result->httpStatusCode);
    }

    public function testToResponseArray(): void
    {
        $handler = $this->createHandler('');

        $request = $this->createRequest(
            '{"event": "contact_update", "name": "crm-1"}',
            'application/json',
            '',
        );

        $result = $handler->handle($request);
        $response = $result->toResponseArray();

        self::assertSame('ok', $response['status']);
        self::assertArrayHasKey('total', $response);
        self::assertArrayHasKey('duration', $response);
    }

    private function createHandler(string $secret): WebhookHandler
    {
        $ccAdapter = $this->createMock(ContactCentreAdapterInterface::class);
        $crmAdapter = $this->createMock(CrmAdapterInterface::class);

        $crmAdapter->method('findContact')
            ->willReturn(Contact::fromArray(['id' => 'crm-1', 'full_name' => 'John', 'email' => 'john@test.com']));

        $ccAdapter->method('upsertContact')
            ->willReturn(Contact::fromArray(['id' => 'cc-1']));

        $contactMapping = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name', SyncDirection::CrmToCc),
            new FieldMapping('email', 'email', SyncDirection::CrmToCc),
        ]);

        $config = new SyncConfiguration(
            instanceUrl: 'https://test.daktela.com',
            accessToken: 'test-token',
            batchSize: 100,
            entities: [
                'contact' => new EntitySyncConfig(true, SyncDirection::CrmToCc, 'contacts.yaml'),
            ],
            mappings: [
                'contact' => $contactMapping,
            ],
            webhookSecret: $secret,
        );

        $engine = new SyncEngine($ccAdapter, $crmAdapter, $config, new NullLogger());

        return new WebhookHandler(
            $engine,
            new WebhookPayloadParser(),
            $secret,
            new NullLogger(),
        );
    }

    private function createRequest(string $body, string $contentType, string $secret): ServerRequestInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getBody')->willReturn($stream);
        $request->method('getHeaderLine')
            ->willReturnMap([
                ['Content-Type', $contentType],
                ['X-Webhook-Secret', $secret],
            ]);

        return $request;
    }
}
