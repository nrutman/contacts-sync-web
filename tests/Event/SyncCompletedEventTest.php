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
}
