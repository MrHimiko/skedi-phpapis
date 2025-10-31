<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

class IntegerArrayType extends Type
{
    public const INTEGER_ARRAY = 'integer_array';

    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string
    {
        return 'integer[]';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): array
    {
        if($value === null) 
        {
            return [];
        }

        return array_map('intval', str_getcsv(trim($value, '{}')));
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): string|null
    {
        if(!is_array($value)) 
        {
            throw new \InvalidArgumentException('Expected an array for integer[] type');
        }

        foreach($value as $element) 
        {
            if(!is_int($element)) 
            {
                throw new \InvalidArgumentException('All elements of the array must be integers');
            }
        }

        if(!count($value))
        {
            return null;
        }

        return '{' . implode(',', $value) . '}';
    }

    public function getName(): string
    {
        return self::INTEGER_ARRAY;
    }
}
