<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Mapping;

use Daktela\CrmSync\Exception\ConfigurationException;
use Daktela\CrmSync\Mapping\MultiValueStrategy;
use Daktela\CrmSync\Mapping\YamlMappingLoader;
use Daktela\CrmSync\Sync\SyncDirection;
use PHPUnit\Framework\TestCase;

final class YamlMappingLoaderTest extends TestCase
{
    private YamlMappingLoader $loader;

    protected function setUp(): void
    {
        $this->loader = new YamlMappingLoader();
    }

    public function testLoadContactsMappingFile(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        self::assertSame('contact', $collection->entityType);
        self::assertSame('email', $collection->lookupField);
        self::assertCount(3, $collection->mappings);
    }

    public function testMappingFieldValues(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        $first = $collection->mappings[0];
        self::assertSame('title', $first->source);
        self::assertSame('full_name', $first->target);
        self::assertSame(SyncDirection::CrmToCc, $first->direction);
    }

    public function testMappingWithTransformers(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        // Third mapping has phone_normalize transformer
        $phone = $collection->mappings[2];
        self::assertCount(1, $phone->transformers);
        self::assertSame('phone_normalize', $phone->transformers[0]['name']);
        self::assertSame('e164', $phone->transformers[0]['params']['format']);
    }

    public function testForDirectionFilter(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        $filtered = $collection->forDirection(SyncDirection::CrmToCc);
        self::assertCount(3, $filtered->mappings);

        $filteredReverse = $collection->forDirection(SyncDirection::CcToCrm);
        self::assertCount(0, $filteredReverse->mappings);
    }

    public function testFileNotFoundThrowsException(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $this->loader->load('/nonexistent/file.yaml');
    }

    public function testLoadMappingWithRelation(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts_with_relation.yaml');

        // Fourth mapping has a relation config
        $accountField = $collection->mappings[3];
        self::assertSame('account', $accountField->source);
        self::assertSame('company_id', $accountField->target);
        self::assertNotNull($accountField->relation);
        self::assertSame('account', $accountField->relation->entity);
        self::assertSame('id', $accountField->relation->resolveFrom);
        self::assertSame('name', $accountField->relation->resolveTo);
    }

    public function testLoadMappingWithMultiValue(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts_with_relation.yaml');

        // Fifth mapping has multi_value config
        $tagsField = $collection->mappings[4];
        self::assertSame('customFields.tags', $tagsField->source);
        self::assertSame('tags', $tagsField->target);
        self::assertNotNull($tagsField->multiValue);
        self::assertSame(MultiValueStrategy::Split, $tagsField->multiValue->strategy);
        self::assertSame(',', $tagsField->multiValue->separator);
    }

    public function testLoadMappingWithoutRelationOrMultiValue(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts.yaml');

        $first = $collection->mappings[0];
        self::assertNull($first->relation);
        self::assertNull($first->multiValue);
    }

    public function testContactsWithRelationHasCorrectCount(): void
    {
        $collection = $this->loader->load(__DIR__ . '/../../Fixtures/mappings/contacts_with_relation.yaml');

        self::assertCount(5, $collection->mappings);
    }
}
