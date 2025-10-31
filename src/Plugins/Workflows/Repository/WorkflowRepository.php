<?php
// src/Plugins/Workflows/Repository/WorkflowRepository.php

namespace App\Plugins\Workflows\Repository;

use App\Plugins\Workflows\Entity\WorkflowEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkflowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowEntity::class);
    }
}