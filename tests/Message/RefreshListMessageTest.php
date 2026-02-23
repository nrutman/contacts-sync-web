<?php

namespace App\Tests\Message;

use App\Message\RefreshListMessage;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class RefreshListMessageTest extends MockeryTestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $message = new RefreshListMessage(
            syncListId: 'list-123',
            triggeredByUserId: 'user-456',
        );

        self::assertSame('list-123', $message->syncListId);
        self::assertSame('user-456', $message->triggeredByUserId);
    }

    public function testConstructorDefaults(): void
    {
        $message = new RefreshListMessage(syncListId: 'list-123');

        self::assertSame('list-123', $message->syncListId);
        self::assertNull($message->triggeredByUserId);
    }

    public function testPropertiesAreReadonly(): void
    {
        $message = new RefreshListMessage(
            syncListId: 'list-123',
            triggeredByUserId: 'user-456',
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
