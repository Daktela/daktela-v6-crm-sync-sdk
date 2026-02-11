<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping;

use Daktela\CrmSync\Entity\Contact;
use Daktela\CrmSync\Mapping\FieldMapper;
use Daktela\CrmSync\Mapping\FieldMapping;
use Daktela\CrmSync\Mapping\MappingCollection;
use Daktela\CrmSync\Mapping\MultiValueConfig;
use Daktela\CrmSync\Mapping\MultiValueStrategy;
use Daktela\CrmSync\Mapping\RelationConfig;
use Daktela\CrmSync\Mapping\Transformer\TransformerRegistry;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;

final class FieldMapperTest extends TestCase
{
    private FieldMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new FieldMapper(TransformerRegistry::withDefaults());
    }

    public function testSimpleCrmToCcMapping(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name', SyncDirection::CrmToCc),
            new FieldMapping('email', 'email', SyncDirection::CrmToCc),
        ]);

        // CRM entity with CRM field names
        $entity = Contact::fromArray([
            'full_name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame('John Doe', $result['title']);
        self::assertSame('john@example.com', $result['email']);
    }

    public function testCcToCrmMapping(): void
    {
        $collection = new MappingCollection('activity', 'name', [
            new FieldMapping('name', 'external_id', SyncDirection::CcToCrm),
            new FieldMapping('title', 'subject', SyncDirection::CcToCrm),
        ]);

        // CC entity with CC field names
        $entity = Contact::fromArray([
            'name' => 'call-123',
            'title' => 'Incoming call',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CcToCrm);

        self::assertSame('call-123', $result['external_id']);
        self::assertSame('Incoming call', $result['subject']);
    }

    public function testDotNotationRead(): void
    {
        $collection = new MappingCollection('account', 'name', [
            new FieldMapping('customFields.industry', 'industry', SyncDirection::CcToCrm),
        ]);

        $entity = Contact::fromArray([
            'customFields' => ['industry' => 'Technology'],
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CcToCrm);

        self::assertSame('Technology', $result['industry']);
    }

    public function testDotNotationWrite(): void
    {
        $collection = new MappingCollection('account', 'name', [
            new FieldMapping('customFields.industry', 'industry', SyncDirection::CrmToCc),
        ]);

        $entity = Contact::fromArray([
            'industry' => 'Finance',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame('Finance', $result['customFields']['industry']);
    }

    public function testTransformerChain(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('number', 'phone', SyncDirection::CrmToCc, [
                ['name' => 'phone_normalize', 'params' => ['format' => 'e164']],
            ]),
        ]);

        $entity = Contact::fromArray([
            'phone' => '(420) 123-456-789',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame('+420123456789', $result['number']);
    }

    public function testDirectionFiltering(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name', SyncDirection::CrmToCc),
            new FieldMapping('name', 'external_id', SyncDirection::CcToCrm),
        ]);

        $entity = Contact::fromArray([
            'full_name' => 'John',
            'external_id' => 'ext-1',
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertArrayHasKey('title', $result);
        self::assertArrayNotHasKey('external_id', $result);
    }

    public function testBidirectionalMappingIncludedInBothDirections(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('description', 'notes', SyncDirection::Bidirectional),
        ]);

        $entityCrm = Contact::fromArray(['notes' => 'CRM notes']);
        $entityCc = Contact::fromArray(['description' => 'CC description']);

        $resultToCc = $this->mapper->map($entityCrm, $collection, SyncDirection::CrmToCc);
        $resultToCrm = $this->mapper->map($entityCc, $collection, SyncDirection::CcToCrm);

        self::assertSame('CRM notes', $resultToCc['description']);
        self::assertSame('CC description', $resultToCrm['notes']);
    }

    public function testNullValuePassedThrough(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping('title', 'full_name', SyncDirection::CrmToCc),
        ]);

        $entity = Contact::fromArray([]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertArrayHasKey('title', $result);
        self::assertNull($result['title']);
    }

    public function testRelationResolution(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                source: 'account',
                target: 'company_id',
                direction: SyncDirection::CrmToCc,
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);

        $entity = Contact::fromArray(['company_id' => 'crm-acc-123']);

        $relationMaps = [
            'account' => ['crm-acc-123' => 'acme', 'crm-acc-456' => 'globex'],
        ];

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, $relationMaps);

        self::assertSame('acme', $result['account']);
    }

    public function testRelationResolutionFallsBackToOriginalValue(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                source: 'account',
                target: 'company_id',
                direction: SyncDirection::CrmToCc,
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);

        $entity = Contact::fromArray(['company_id' => 'unknown-id']);

        $relationMaps = [
            'account' => ['crm-acc-123' => 'acme'],
        ];

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, $relationMaps);

        // Falls back to original value when not found in map
        self::assertSame('unknown-id', $result['account']);
    }

    public function testRelationResolutionSkipsNullValues(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                source: 'account',
                target: 'company_id',
                direction: SyncDirection::CrmToCc,
                relation: new RelationConfig('account', 'id', 'name'),
            ),
        ]);

        $entity = Contact::fromArray([]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, ['account' => ['x' => 'y']]);

        self::assertNull($result['account']);
    }

    public function testMultiValueSplitApplied(): void
    {
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                source: 'customFields.tags',
                target: 'tags',
                direction: SyncDirection::CrmToCc,
                multiValue: new MultiValueConfig(MultiValueStrategy::Split, ','),
            ),
        ]);

        $entity = Contact::fromArray(['tags' => 'web,mobile,api']);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc);

        self::assertSame(['web', 'mobile', 'api'], $result['customFields']['tags']);
    }

    public function testMultiValueJoinApplied(): void
    {
        $collection = new MappingCollection('activity', 'name', [
            new FieldMapping(
                source: 'customFields.interests',
                target: 'interests',
                direction: SyncDirection::CcToCrm,
                multiValue: new MultiValueConfig(MultiValueStrategy::Join, ', '),
            ),
        ]);

        $entity = Contact::fromArray([
            'customFields' => ['interests' => ['sports', 'music']],
        ]);

        $result = $this->mapper->map($entity, $collection, SyncDirection::CcToCrm);

        self::assertSame('sports, music', $result['interests']);
    }

    public function testRelationAndMultiValueCombined(): void
    {
        // Relation resolution happens before multi-value, so this tests the order
        $collection = new MappingCollection('contact', 'email', [
            new FieldMapping(
                source: 'account',
                target: 'company_id',
                direction: SyncDirection::CrmToCc,
                relation: new RelationConfig('account', 'id', 'name'),
            ),
            new FieldMapping(
                source: 'customFields.tags',
                target: 'tags',
                direction: SyncDirection::CrmToCc,
                multiValue: new MultiValueConfig(MultiValueStrategy::Split, ','),
            ),
        ]);

        $entity = Contact::fromArray([
            'company_id' => 'acc-1',
            'tags' => 'vip,enterprise',
        ]);

        $relationMaps = ['account' => ['acc-1' => 'acme']];

        $result = $this->mapper->map($entity, $collection, SyncDirection::CrmToCc, $relationMaps);

        self::assertSame('acme', $result['account']);
        self::assertSame(['vip', 'enterprise'], $result['customFields']['tags']);
    }
}
