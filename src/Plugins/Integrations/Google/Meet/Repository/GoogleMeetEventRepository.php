<?php

namespace App\Plugins\Integrations\Google\Meet\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Plugins\Integrations\Google\Meet\Entity\GoogleMeetEventEntity;

class GoogleMeetEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleMeetEventEntity::class);
    }
}