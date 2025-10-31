<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

class VarcharArrayType extends Type
{
    public const VARCHAR_ARRAY = 'varchar_array';

    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string
    {
        return "character varying[]";
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if ($value === null) {
            return [];
        }

        // Convert PostgreSQL array string to PHP array
        return str_getcsv(trim($value, '{}'));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Expected an array for character varying[] type');
        }

        // Convert PHP array to PostgreSQL array string
        return '{' . implode(',', $value) . '}';
    }

    public function getName(): string
    {
        return self::VARCHAR_ARRAY;
    }
}
