<?php

namespace App\Plugins\PotentialLeads\Repository;

use App\Plugins\PotentialLeads\Entity\OrganizationPotentialLeadEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrganizationPotentialLeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationPotentialLeadEntity::class);
    }
}