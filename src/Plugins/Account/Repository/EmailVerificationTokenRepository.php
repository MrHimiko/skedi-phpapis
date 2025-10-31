<?php

namespace App\Plugins\Account\Repository;

use App\Plugins\Account\Entity\EmailVerificationTokenEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class EmailVerificationTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailVerificationTokenEntity::class);
    }
}