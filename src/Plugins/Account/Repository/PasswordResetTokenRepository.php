<?php

namespace App\Plugins\Account\Repository;

use App\Plugins\Account\Entity\PasswordResetTokenEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetTokenEntity::class);
    }
}