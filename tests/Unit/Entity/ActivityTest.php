<?php

declare(strict_types=1);

namespace Daktela\CrmSync\Tests\Unit\Entity;

use Daktela\CrmSync\Entity\Activity;
use Daktela\CrmSync\Entity\ActivityType;
use PHPUnit\Framework\TestCase;

final class ActivityTest extends TestCase
{
    public function testFromArrayWithActivityType(): void
    {
        $activity = Activity::fromArray([
            'id' => 'act-1',
            'activity_type' => 'call',
            'title' => 'Incoming call',
        ]);

        self::assertSame('act-1', $activity->getId());
        self::assertSame('activity', $activity->getType());
        self::assertSame(ActivityType::Call, $activity->getActivityType());
        self::assertSame('Incoming call', $activity->get('title'));
    }

    public function testFromArrayWithoutActivityType(): void
    {
        $activity = Activity::fromArray([
            'id' => 'act-2',
            'title' => 'Some activity',
        ]);

        self::assertNull($activity->getActivityType());
    }

    public function testSetActivityType(): void
    {
        $activity = new Activity('act-3');
        $activity->setActivityType(ActivityType::Email);

        self::assertSame(ActivityType::Email, $activity->getActivityType());
    }

    public function testToArrayIncludesActivityType(): void
    {
        $activity = Activity::fromArray([
            'id' => 'act-4',
            'activity_type' => 'email',
            'title' => 'Test',
        ]);

        $array = $activity->toArray();

        self::assertSame('email', $array['activity_type']);
        self::assertSame('act-4', $array['id']);
        self::assertSame('Test', $array['title']);
    }

    public function testGetData(): void
    {
        $activity = Activity::fromArray([
            'id' => 'act-5',
            'activity_type' => 'sms',
            'title' => 'SMS message',
        ]);

        $data = $activity->getData();

        self::assertArrayHasKey('title', $data);
        self::assertArrayNotHasKey('id', $data);
        self::assertArrayNotHasKey('activity_type', $data);
    }
}
