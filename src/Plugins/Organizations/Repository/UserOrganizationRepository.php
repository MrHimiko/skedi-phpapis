<?php

namespace App\Plugins\Organizations\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Plugins\Organizations\Entity\UserOrganizationEntity;

class UserOrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserOrganizationEntity::class);
    }

    // You can add custom query methods here if needed.
}
