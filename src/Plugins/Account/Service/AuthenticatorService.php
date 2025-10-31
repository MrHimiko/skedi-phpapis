<?php

namespace App\Plugins\Account\Service;

use App\Plugins\Account\Entity\UserEntity;

use App\Plugins\Account\Repository\TokenRepository;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

class AuthenticatorService
{
    private TokenRepository $tokenRepository;

    public function __construct(TokenRepository $tokenRepository)
    {
        $this->tokenRepository = $tokenRepository;
    }

    public function getUser(?string $authorization, ?string $permission): ?UserEntity
    {
        if(!$authorization = $this->getAuthorization($authorization))
        {
            return null;
        }

        if(!$token = $this->tokenRepository->findOneBy(['id' => $authorization->id]))
        {
            return null;
        }

        if($token->getValue() !== $authorization->token)
        {
            return null;
        }

        $user = $token->getUser();

        return $user;
    }

    private function getAuthorization(?string $authorization): ?object
    {
        if($authorization === null)
        {
            return null;
        }

        if(!str_starts_with($authorization, 'Bearer ')) 
        {
            return null;
        }

        $token = substr($authorization, 7);
        $parts = explode(':', base64_decode($token));

        if(count($parts) !== 3)
        {
            return null;
        }

        return (object) [
            'id'           => (int) $parts[0],
            'value'        => $parts[1],
            'expires'      => (int) $parts[2],
            'token'        => $token
        ];
    }
}
