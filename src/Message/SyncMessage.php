<?php

namespace App\Message;

class SyncMessage
{
    public function __construct(
        public readonly string $syncListId,
        public readonly bool $dryRun = false,
        public readonly ?string $triggeredByUserId = null,
        public readonly string $trigger = 'manual',
        public readonly ?string $syncRunId = null,
    ) {
    }
}
