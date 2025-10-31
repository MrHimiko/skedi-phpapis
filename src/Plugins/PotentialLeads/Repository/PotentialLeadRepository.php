<?php

namespace App\Plugins\PotentialLeads\Repository;

use App\Plugins\PotentialLeads\Entity\PotentialLeadEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PotentialLeadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PotentialLeadEntity::class);
    }
}