<?php

namespace App\EventListener;

use App\Doctrine\Type\EncryptedType;
use App\Security\EncryptionService;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class EncryptedTypeBootstrap implements EventSubscriberInterface
{
    private readonly EncryptionService $encryptionService;
    private bool $initialized = false;

    public function __construct(EncryptionService $encryptionService)
    {
        $this->encryptionService = $encryptionService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onEarlyEvent', 1024],
            ConsoleEvents::COMMAND => ['onEarlyEvent', 1024],
        ];
    }

    public function onEarlyEvent(): void
    {
        if ($this->initialized) {
            return;
        }

        EncryptedType::setEncryptionService($this->encryptionService);
        $this->initialized = true;
    }
}
