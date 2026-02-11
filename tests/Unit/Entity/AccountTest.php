<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Entity;

use Daktela\CrmSync\Entity\Account;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function testFromArray(): void
    {
        $account = Account::fromArray([
            'id' => 'a-1',
            'title' => 'Acme Corp',
            'name' => 'acme',
        ]);

        self::assertSame('a-1', $account->getId());
        self::assertSame('account', $account->getType());
        self::assertSame('Acme Corp', $account->get('title'));
        self::assertSame('acme', $account->get('name'));
    }

    public function testToArray(): void
    {
        $account = Account::fromArray([
            'id' => 'a-2',
            'title' => 'Test Inc',
        ]);

        $array = $account->toArray();

        self::assertSame('a-2', $array['id']);
        self::assertSame('Test Inc', $array['title']);
    }

    public function testSetAndGet(): void
    {
        $account = new Account('a-3');
        $account->set('title', 'New Company');

        self::assertSame('New Company', $account->get('title'));
        self::assertNull($account->get('nonexistent'));
    }
}
