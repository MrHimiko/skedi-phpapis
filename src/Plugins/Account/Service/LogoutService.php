<?php

namespace App\Plugins\Account\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Account\Entity\TokenEntity;
use App\Plugins\Account\Entity\UserEntity;

use App\Plugins\Account\Exception\AccountException;

class LogoutService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function logout(UserEntity $user): void
    {
        $tokens = $this->entityManager->getRepository(TokenEntity::class)->findBy([
            'organization' => $user->getOrganization(),
            'user' => $user
        ]);

        foreach ($tokens as $token)
        {
            $this->entityManager->remove($token);
        }

        $this->entityManager->flush();
    }
}
