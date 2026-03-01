<?php

namespace App\Tests\Entity;

use App\Entity\SyncRun;
use App\Entity\SyncRunContact;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class SyncRunContactTest extends MockeryTestCase
{
    public function testConstruction(): void
    {
        $contact = new SyncRunContact();

        self::assertNotNull($contact->getId());
    }

    public function testGettersAndSetters(): void
    {
        $contact = new SyncRunContact();
        $syncRun = new SyncRun();

        $contact->setName('John Doe');
        $contact->setEmail('john@example.com');
        $contact->setSyncRun($syncRun);

        self::assertEquals('John Doe', $contact->getName());
        self::assertEquals('john@example.com', $contact->getEmail());
        self::assertSame($syncRun, $contact->getSyncRun());
    }

    public function testNullableEmail(): void
    {
        $contact = new SyncRunContact();
        $contact->setName('No Email');
        $contact->setEmail(null);

        self::assertNull($contact->getEmail());
    }

    public function testImplementsContactInterface(): void
    {
        $contact = new SyncRunContact();

        self::assertInstanceOf(\App\Contact\ContactInterface::class, $contact);
    }
}
