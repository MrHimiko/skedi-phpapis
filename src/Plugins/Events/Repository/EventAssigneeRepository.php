<?php

namespace App\Plugins\Events\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

use App\Plugins\Events\Entity\EventAssigneeEntity;

class EventAssigneeRepository extends ServiceEntityRepository
{
   public function __construct(ManagerRegistry $registry)
   {
       parent::__construct($registry, EventAssigneeEntity::class);
   }
   
   public function findEventsByAssignee(int $userId)
   {
       return $this->findBy(['user' => $userId]);
   }
}