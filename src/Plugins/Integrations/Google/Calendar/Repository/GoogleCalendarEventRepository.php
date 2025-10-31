<?php

namespace App\Plugins\Integrations\Google\Calendar\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Plugins\Integrations\Google\Calendar\Entity\GoogleCalendarEventEntity;

class GoogleCalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleCalendarEventEntity::class);
    }
    
}