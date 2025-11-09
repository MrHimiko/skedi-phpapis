<?php

namespace App\Listener;

use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpFoundation\JsonResponse;



class ExceptionListener
{
    


    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();

       
    }
}