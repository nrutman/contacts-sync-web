<?php

namespace App\Client\Provider;

class ProviderNotFoundException extends \RuntimeException
{
    public function __construct(string $name)
    {
        parent::__construct(sprintf('Provider "%s" not found.', $name));
    }
}
