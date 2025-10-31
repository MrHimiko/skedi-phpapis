<?php

namespace App\Listener;

use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpFoundation\JsonResponse;

use App\Service\SlackService;

class ExceptionListener
{
    private SlackService $slackService;

    public function __construct(SlackService $slackService)
    {
        $this->slackService = $slackService;
    }

    public function onKernelException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();

        $this->slackService->message(implode(' | ', [
            'Message: ' . $exception->getMessage(),
            'File: ' . $exception->getFile(),
            'Line: ' . $exception->getLine()
        ]));
    }
}