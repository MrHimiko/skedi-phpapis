<?php 

namespace App\Plugins\Workflows\Repository;

use App\Plugins\Workflows\Entity\WorkflowExecutionEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class WorkflowExecutionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowExecutionEntity::class);
    }
}