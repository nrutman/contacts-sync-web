<?php

namespace App\Sync;

class SyncResult
{
    public function __construct(
        public readonly int $sourceCount,
        public readonly int $destinationCount,
        public readonly int $addedCount,
        public readonly int $removedCount,
        public readonly string $log,
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
