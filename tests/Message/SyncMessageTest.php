<?php

namespace App\Tests\Message;

use App\Message\SyncMessage;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class SyncMessageTest extends MockeryTestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $message = new SyncMessage(
            syncListId: 'list-123',
            dryRun: true,
            triggeredByUserId: 'user-456',
            trigger: 'manual',
            syncRunId: 'run-789',
        );

        self::assertSame('list-123', $message->syncListId);
        self::assertTrue($message->dryRun);
        self::assertSame('user-456', $message->triggeredByUserId);
        self::assertSame('manual', $message->trigger);
        self::assertSame('run-789', $message->syncRunId);
    }

    public function testConstructorDefaults(): void
    {
        $message = new SyncMessage(syncListId: 'list-123');

        self::assertSame('list-123', $message->syncListId);
        self::assertFalse($message->dryRun);
        self::assertNull($message->triggeredByUserId);
        self::assertSame('manual', $message->trigger);
        self::assertNull($message->syncRunId);
    }

    public function testScheduleTrigger(): void
    {
        $message = new SyncMessage(
            syncListId: 'list-123',
            trigger: 'schedule',
        );

        self::assertSame('schedule', $message->trigger);
        self::assertNull($message->triggeredByUserId);
        self::assertNull($message->syncRunId);
    }

    public function testPropertiesAreReadonly(): void
    {
        $message = new SyncMessage(
            syncListId: 'list-123',
            dryRun: false,
            triggeredByUserId: 'user-456',
            trigger: 'manual',
            syncRunId: 'run-789',
        );

        $reflection = new \ReflectionClass($message);

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), sprintf(
                'Property "%s" should be readonly.',
                $property->getName(),
            ));
        }
    }
}
