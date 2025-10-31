<?php

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

class ObjectArrayType extends Type
{
    public const JSON_ARRAY = 'object_array';

    public function getSQLDeclaration(array $fieldDeclaration, AbstractPlatform $platform): string
    {
        return "jsonb[]"; 
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if($value === null) 
        {
            return [];
        }

        $elements = str_getcsv(trim($value, '{}'));

        if(!is_array($elements))
        {
            return [];
        }

        foreach($elements as $index => &$value)
        {
            $value = json_decode(stripslashes($value), true);

            if(is_array($value))
            {
                $value = (object) $value;
            }

            if(!is_object($value))
            {
                unset($elements[$index]);
            }
        }

        return $elements;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): mixed
    {
        if(!is_array($value)) 
        {
            return null;
        }

        $elements = [];

        foreach($value as $item) 
        {
            if($json = json_encode($item))
            {
                $elements[] = '"' . addcslashes($json, '"\\') . '"';
            }
        }

        if(!count($elements))
        {
            return null;
        }

        return '{' . implode(',', $elements) . '}';
    }

    public function getName(): string
    {
        return self::JSON_ARRAY;
    }
}