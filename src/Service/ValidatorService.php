<?php

namespace App\Service;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints\Collection;

class ValidatorService
{
    public function toArray(Collection $constraints, array $data): ?array
    {
        $violations = Validation::createValidator()->validate($data, $constraints);
        $messages = [];

        if(!count($violations)) 
        {
            return null;
        }

        foreach($violations as $error) 
        {
            $field = $error->getPropertyPath();
            $message = $error->getMessage();

            $messages[$field] = $field . ' - ' . $message;
        }

        return $messages;
    }
}