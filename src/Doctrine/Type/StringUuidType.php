<?php

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Symfony\Bridge\Doctrine\Types\AbstractUidType;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Uuid;

final class StringUuidType extends AbstractUidType
{
    public const NAME = 'uuid_string';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function getUidClass(): string
    {
        return Uuid::class;
    }

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getGuidTypeDeclarationSQL($column);
    }

    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof AbstractUid) {
            return $value->toRfc4122();
        }

        if ($value === null || $value === '') {
            return null;
        }

        if (!\is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Expected string, Uuid, or null; got %s.', get_debug_type($value)));
        }

        return Uuid::fromString($value)->toRfc4122();
    }
}
