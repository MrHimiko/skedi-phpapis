<?php

namespace App\Plugins\Billing\Repository;

use App\Plugins\Billing\Entity\OrganizationSubscriptionEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrganizationSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationSubscriptionEntity::class);
    }
}