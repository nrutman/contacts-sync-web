<?php

namespace App\Client\Provider;

class CredentialFieldDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly bool $required = true,
        public readonly bool $sensitive = false,
        public readonly ?string $help = null,
        public readonly ?string $placeholder = null,
    ) {
    }
}
