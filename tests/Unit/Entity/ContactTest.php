<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Entity;

use Daktela\CrmSync\Entity\Contact;
use PHPUnit\Framework\TestCase;

final class ContactTest extends TestCase
{
    public function testFromArray(): void
    {
        $contact = Contact::fromArray([
            'id' => 'c-1',
            'email' => 'john@example.com',
            'title' => 'John Doe',
        ]);

        self::assertSame('c-1', $contact->getId());
        self::assertSame('contact', $contact->getType());
        self::assertSame('john@example.com', $contact->get('email'));
        self::assertSame('John Doe', $contact->get('title'));
    }

    public function testToArray(): void
    {
        $contact = Contact::fromArray([
            'id' => 'c-2',
            'email' => 'jane@example.com',
        ]);

        $array = $contact->toArray();

        self::assertSame('c-2', $array['id']);
        self::assertSame('jane@example.com', $array['email']);
    }

    public function testSetAndGet(): void
    {
        $contact = new Contact('c-3');
        $contact->set('email', 'test@example.com');

        self::assertSame('test@example.com', $contact->get('email'));
        self::assertNull($contact->get('nonexistent'));
    }

    public function testGetData(): void
    {
        $contact = Contact::fromArray([
            'id' => 'c-1',
            'email' => 'john@example.com',
            'title' => 'John',
        ]);

        $data = $contact->getData();

        self::assertArrayHasKey('email', $data);
        self::assertArrayNotHasKey('id', $data);
    }

    public function testNullId(): void
    {
        $contact = Contact::fromArray(['email' => 'no-id@example.com']);

        self::assertNull($contact->getId());
        $array = $contact->toArray();
        self::assertArrayNotHasKey('id', $array);
    }
}
