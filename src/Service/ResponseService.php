<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\HttpFoundation\RequestStack;


class ResponseService
{

    private RequestStack $requestStack;

    public function __construct(
   
        RequestStack $requestStack
    )
    {
    
        $this->requestStack = $requestStack;
    }

    public function json(bool $success, string|Exception $message, mixed $data = null, int $status = 200): JsonResponse
    {
        if (gettype($message) === 'object') 
        {
            $success = false;
            $message = 'An unexpected error occurred. Please contact support if the problem persists.';
            $status = 500;

            
        } 
        else if ($message === 'deny') 
        {
            $message = 'You do not have permission to access this resource. Please provide valid credentials.';
            $status = 403;
        } 
        else if ($message === 'not-found') 
        {
            $message = 'The requested resource was not found.';
            $status = 404;
        } 
        else if ($message === 'update') 
        {
            $message = 'Resources updated successfully.';
            $status = 200;
        } 
        else if ($message === 'create') 
        {
            $message = 'Resources created successfully.';
            $status = 201;
        } 
        else if ($message === 'delete') 
        {
            $message = 'Resources deleted successfully.';
            $status = 200;
        } 
        else if ($message === 'retrieve') 
        {
            $message = 'Resources retrieved successfully.';
            $status = 200;
        }

        $currentRequest = $this->requestStack->getCurrentRequest();
        $method = $currentRequest ? $currentRequest->getMethod() : 'N/A';

        return new JsonResponse([
            'success' => $success,
            'message' => $message,
            'data'    => $data,
            'method'  => $method
        ], $status);
    }

    public function jsonValidator(ConstraintViolationList $errors): ?JsonResponse
    {
        if (!count($errors)) 
        {
            return null;
        }

        $messages = [];

        foreach ($errors as $error) 
        {
            $field = $error->getPropertyPath();
            $message = $error->getMessage();

            $messages[$field] = $message;
        }

        return $this->json(false, 'Validation failed.', ['errors' => (object) $messages], 400);
    }
}
