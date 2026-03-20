<?php

namespace App\Tests\Doctrine\Type;

use App\Doctrine\Type\EncryptedType;
use App\EventListener\EncryptedTypeBootstrap;
use App\Security\EncryptionService;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;

class EncryptedTypeBootstrapTest extends MockeryTestCase
{
    protected function tearDown(): void
    {
        EncryptedType::setEncryptionService(new EncryptionService(bin2hex(random_bytes(32))));
        parent::tearDown();
    }

    public function testOnEarlyEventInjectsServiceIntoType(): void
    {
        $service = new EncryptionService(bin2hex(random_bytes(32)));
        $bootstrap = new EncryptedTypeBootstrap($service);

        $bootstrap->onEarlyEvent();

        self::assertSame($service, EncryptedType::getEncryptionService());
    }

    public function testSubscribesToBothWebAndConsoleEvents(): void
    {
        $events = EncryptedTypeBootstrap::getSubscribedEvents();

        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertArrayHasKey(ConsoleEvents::COMMAND, $events);
    }
}
