<?php

namespace App\Event;

use App\Entity\SyncRun;

class SyncCompletedEvent
{
    public function __construct(
        public readonly SyncRun $syncRun,
    ) {
    }
}
