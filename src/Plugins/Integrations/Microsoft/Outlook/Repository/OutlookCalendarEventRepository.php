<?php

namespace App\Plugins\Integrations\Microsoft\Outlook\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Plugins\Integrations\Microsoft\Outlook\Entity\OutlookCalendarEventEntity;

class OutlookCalendarEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OutlookCalendarEventEntity::class);
    }
}