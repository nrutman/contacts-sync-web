<?php

namespace App\Client\Provider;

enum ProviderCapability: string
{
    case Source = 'source';
    case Destination = 'destination';
}
