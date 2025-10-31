<?php

namespace App\Plugins\Integrations\Common\Repository;


use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Account\Entity\UserEntity;

class IntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IntegrationEntity::class);
    }
    
    public function findByUser(UserEntity $user, ?string $provider = null)
    {
        $criteria = ['user' => $user, 'status' => 'active'];
        
        if ($provider) {
            $criteria['provider'] = $provider;
        }
        
        return $this->findBy($criteria, ['created' => 'DESC']);
    }
}