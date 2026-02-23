<?php

namespace App\Tests\Event;

use App\Entity\SyncRun;
use App\Event\SyncCompletedEvent;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class SyncCompletedEventTest extends MockeryTestCase
{
    public function testConstructorStoresSyncRun(): void
    {
        $syncRun = new SyncRun();
        $event = new SyncCompletedEvent($syncRun);

        self::assertSame($syncRun, $event->syncRun);
    }

    public function testSyncRunPropertyIsReadonly(): void
    {
        $reflection = new \ReflectionProperty(SyncCompletedEvent::class, 'syncRun');

        self::assertTrue($reflection->isReadOnly());
        self::assertTrue($reflection->isPublic());
    }
}
