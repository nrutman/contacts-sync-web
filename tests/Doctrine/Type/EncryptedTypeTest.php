<?php

namespace App\Tests\Doctrine\Type;

use App\Doctrine\Type\EncryptedType;
use App\Security\EncryptionService;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class EncryptedTypeTest extends MockeryTestCase
{
    private EncryptionService $encryptionService;
    private EncryptedType $type;
    private AbstractPlatform $platform;

    protected function setUp(): void
    {
        $this->encryptionService = new EncryptionService(bin2hex(random_bytes(32)));
        EncryptedType::setEncryptionService($this->encryptionService);
        $this->type = new EncryptedType();
        $this->platform = \Mockery::mock(AbstractPlatform::class);
    }

    public function testConvertToDatabaseValueEncryptsPlaintext(): void
    {
        $result = $this->type->convertToDatabaseValue('{"token":"abc"}', $this->platform);

        self::assertIsString($result);
        self::assertStringStartsWith('v', $result);
        self::assertNotSame('{"token":"abc"}', $result);
    }

    public function testConvertToPHPValueDecryptsCiphertext(): void
    {
        $encrypted = $this->encryptionService->encrypt('{"token":"abc"}');

        $result = $this->type->convertToPHPValue($encrypted, $this->platform);

        self::assertSame('{"token":"abc"}', $result);
    }

    public function testRoundTrip(): void
    {
        $original = '{"client_id":"xxx","client_secret":"yyy"}';

        $dbValue = $this->type->convertToDatabaseValue($original, $this->platform);
        $phpValue = $this->type->convertToPHPValue($dbValue, $this->platform);

        self::assertSame($original, $phpValue);
    }

    public function testNullPassesThrough(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testEmptyStringPassesThrough(): void
    {
        self::assertSame('', $this->type->convertToDatabaseValue('', $this->platform));
        self::assertSame('', $this->type->convertToPHPValue('', $this->platform));
    }

    public function testGetName(): void
    {
        self::assertSame('encrypted', $this->type->getName());
    }

    public function testGetSQLDeclaration(): void
    {
        $this->platform->shouldReceive('getClobTypeDeclarationSQL')
            ->once()
            ->with(['length' => 0])
            ->andReturn('TEXT');

        $result = $this->type->getSQLDeclaration(['length' => 0], $this->platform);

        self::assertSame('TEXT', $result);
    }
}
