<?php

namespace App\Tests\Sync;

use App\Sync\SyncResult;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class SyncResultTest extends MockeryTestCase
{
    public function testConstructorSetsAllProperties(): void
    {
        $result = new SyncResult(
            sourceCount: 10,
            destinationCount: 8,
            addedCount: 3,
            removedCount: 1,
            log: "Some log output\n",
            success: true,
        );

        self::assertEquals(10, $result->sourceCount);
        self::assertEquals(8, $result->destinationCount);
        self::assertEquals(3, $result->addedCount);
        self::assertEquals(1, $result->removedCount);
        self::assertEquals("Some log output\n", $result->log);
        self::assertTrue($result->success);
        self::assertNull($result->errorMessage);
    }

    public function testConstructorWithErrorMessage(): void
    {
        $result = new SyncResult(
            sourceCount: 0,
            destinationCount: 0,
            addedCount: 0,
            removedCount: 0,
            log: "ERROR: Connection refused\n",
            success: false,
            errorMessage: 'Connection refused',
        );

        self::assertEquals(0, $result->sourceCount);
        self::assertEquals(0, $result->destinationCount);
        self::assertEquals(0, $result->addedCount);
        self::assertEquals(0, $result->removedCount);
        self::assertFalse($result->success);
        self::assertEquals('Connection refused', $result->errorMessage);
    }

    public function testErrorMessageDefaultsToNull(): void
    {
        $result = new SyncResult(
            sourceCount: 5,
            destinationCount: 5,
            addedCount: 0,
            removedCount: 0,
            log: '',
            success: true,
        );

        self::assertNull($result->errorMessage);
    }

    public function testPropertiesAreReadonly(): void
    {
        $result = new SyncResult(
            sourceCount: 1,
            destinationCount: 2,
            addedCount: 3,
            removedCount: 4,
            log: 'test',
            success: true,
            errorMessage: null,
        );

        $reflection = new \ReflectionClass($result);

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue(
                $property->isReadOnly(),
                sprintf('Property "%s" should be readonly', $property->getName()),
            );
        }
    }

    public function testSuccessfulResultWithZeroCounts(): void
    {
        $result = new SyncResult(
            sourceCount: 0,
            destinationCount: 0,
            addedCount: 0,
            removedCount: 0,
            log: '',
            success: true,
        );

        self::assertTrue($result->success);
        self::assertEquals(0, $result->sourceCount);
        self::assertEquals(0, $result->destinationCount);
        self::assertEquals(0, $result->addedCount);
        self::assertEquals(0, $result->removedCount);
    }
}
