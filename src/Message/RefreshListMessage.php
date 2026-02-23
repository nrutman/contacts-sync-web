<?php

namespace App\Message;

class RefreshListMessage
{
    public function __construct(
        public readonly string $syncListId,
        public readonly ?string $triggeredByUserId = null,
    ) {
    }
}
