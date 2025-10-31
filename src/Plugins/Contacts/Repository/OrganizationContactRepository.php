<?php

namespace App\Plugins\Contacts\Repository;

use App\Plugins\Contacts\Entity\OrganizationContactEntity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrganizationContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationContactEntity::class);
    }
}