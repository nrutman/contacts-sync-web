<?php

namespace App\Doctrine\Type;

use App\Security\EncryptionService;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

final class EncryptedType extends Type
{
    public const NAME = 'encrypted';

    private static ?EncryptionService $encryptionService = null;

    public static function setEncryptionService(EncryptionService $service): void
    {
        self::$encryptionService = $service;
    }

    public static function getEncryptionService(): ?EncryptionService
    {
        return self::$encryptionService;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getClobTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return '';
        }

        return self::getService()->encrypt($value);
    }

    public function convertToPHPValue($value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return '';
        }

        return self::getService()->decrypt($value);
    }

    private static function getService(): EncryptionService
    {
        if (self::$encryptionService === null) {
            throw new \RuntimeException('EncryptionService has not been set on EncryptedType. Call EncryptedType::setEncryptionService() during kernel boot.');
        }

        return self::$encryptionService;
    }
}
