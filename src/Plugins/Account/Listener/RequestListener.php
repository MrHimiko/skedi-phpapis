<?php

namespace App\Plugins\Account\Listener;

use App\Plugins\Account\Service\AuthenticatorService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Teams\Service\UserTeamService;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use App\Service\ResponseService;

class RequestListener
{
    private ResponseService $responseService;
    private AuthenticatorService $authenticatorService;
    private UserOrganizationService $userOrganizationService;
    private UserTeamService $userTeamService;

    public function __construct(
        ResponseService $responseService,
        AuthenticatorService $authenticatorService,
        UserOrganizationService $userOrganizationService,
        UserTeamService $userTeamService
    )
    {
        $this->responseService = $responseService;
        $this->authenticatorService = $authenticatorService;
        $this->userOrganizationService = $userOrganizationService;
        $this->userTeamService = $userTeamService;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        $route = explode('#', $request->attributes->get('_route'));

        if(count($route) === 2)
        {
            if(!$user = $this->authenticatorService->getUser($request->headers->get('Authorization'), empty($route[1]) ? null : $route[1])) 
            {
                $event->setResponse($this->responseService->json(false, 'deny', ['permission' => $route[1]]));
                return;
            }

            $request->attributes->set('user', $user);
            $request->attributes->set('organizations', $this->userOrganizationService->getOrganizationsByUser($user));
            $request->attributes->set('teams', $this->userTeamService->getTeamsByUser($user));
        }
    }
}