<?php

namespace App\Tests\Doctrine\Type;

use App\Doctrine\Type\StringUuidType;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Symfony\Component\Uid\Uuid;

class StringUuidTypeTest extends MockeryTestCase
{
    private StringUuidType $type;

    protected function setUp(): void
    {
        $this->type = new StringUuidType();
    }

    public function testNameReturnsUuidString(): void
    {
        $this->assertSame('uuid_string', $this->type->getName());
        $this->assertSame('uuid_string', StringUuidType::NAME);
    }

    public function testGetSqlDeclarationReturnCharOnMysql(): void
    {
        $platform = new MySQLPlatform();
        $declaration = $this->type->getSQLDeclaration([], $platform);

        $this->assertSame('CHAR(36)', $declaration);
    }

    public function testGetSqlDeclarationReturnUuidOnPostgres(): void
    {
        $platform = new PostgreSQLPlatform();
        $declaration = $this->type->getSQLDeclaration([], $platform);

        $this->assertSame('UUID', $declaration);
    }

    public function testConvertToDatabaseValueWithUuidObject(): void
    {
        $platform = new MySQLPlatform();
        $uuid = Uuid::v7();

        $result = $this->type->convertToDatabaseValue($uuid, $platform);

        $this->assertSame($uuid->toRfc4122(), $result);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result,
        );
    }

    public function testConvertToDatabaseValueWithStringUuid(): void
    {
        $platform = new MySQLPlatform();
        $uuidString = '019c9bf7-6cbc-74fe-b1ba-7a7b35019bb9';

        $result = $this->type->convertToDatabaseValue($uuidString, $platform);

        $this->assertSame($uuidString, $result);
    }

    public function testConvertToDatabaseValueWithNull(): void
    {
        $platform = new MySQLPlatform();

        $this->assertNull($this->type->convertToDatabaseValue(null, $platform));
    }

    public function testConvertToDatabaseValueWithEmptyString(): void
    {
        $platform = new MySQLPlatform();

        $this->assertNull($this->type->convertToDatabaseValue('', $platform));
    }

    public function testConvertToPHPValueReturnsUuidObject(): void
    {
        $platform = new MySQLPlatform();
        $uuidString = '019c9bf7-6cbc-74fe-b1ba-7a7b35019bb9';

        $result = $this->type->convertToPHPValue($uuidString, $platform);

        $this->assertInstanceOf(Uuid::class, $result);
        $this->assertSame($uuidString, $result->toRfc4122());
    }

    public function testConvertToPHPValueWithNull(): void
    {
        $platform = new MySQLPlatform();

        $this->assertNull($this->type->convertToPHPValue(null, $platform));
    }

    public function testConvertToPHPValuePassesThroughUuidObject(): void
    {
        $platform = new MySQLPlatform();
        $uuid = Uuid::v7();

        $result = $this->type->convertToPHPValue($uuid, $platform);

        $this->assertSame($uuid, $result);
    }
}
