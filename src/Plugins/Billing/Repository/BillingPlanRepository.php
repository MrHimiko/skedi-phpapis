<?php

namespace App\Plugins\Billing\Repository;

use App\Plugins\Billing\Entity\BillingPlanEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class BillingPlanRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingPlanEntity::class);
    }
}