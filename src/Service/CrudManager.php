<?php

namespace App\Service;

use Symfony\Component\Validator\Constraints as Assert;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use App\Service\FilterService;
use App\Exception\CrudException;
use App\Exception\FilterException;
use App\Service\ValidatorService;

class CrudManager
{
    private EntityManagerInterface $entityManager;
    private FilterService $filterService;
    private validatorService $validatorService;

    public function __construct(
        EntityManagerInterface $entityManager, 
        FilterService $filterService,
        ValidatorService $validatorService
    )
    {
        $this->entityManager = $entityManager;
        $this->filterService = $filterService;
        $this->validatorService = $validatorService;
    }

    public function findOne(string $entityClass, int $id, array $criteria = []): ?object
    {
        return $this->entityManager->getRepository($entityClass)->findOneBy(['id' => $id] + $criteria);
    }

    public function findMany(string $entityClass, array $filters, int $page, int $limit, array $criteria = [], ?callable $callback = null, bool $count = false): array
    {
        try 
        {
            return $this->filterService->applyAndRun($entityClass, $filters, $page, $limit, function (QueryBuilder $queryBuilder) use($criteria, $callback)
            {
                foreach($criteria as $key => $value)
                {
                    $queryBuilder->andWhere("t1.$key = :$key")->setParameter($key, $value);
                }

                if($callback)
                {
                    $callback($queryBuilder);
                }
            }, $count);
        } 
        catch(FilterException $e) 
        {
            throw new CrudException($e->getMessage());
        } 
    }

    public function create(object $entity, array $data, array $fields = [], array $callbacks = []): void
    {
        $this->validate($data, $fields);
        $this->setEntityData($entity, $data, $callbacks, false);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function update(object $entity, array $data, array $fields = [], array $callbacks = []): void
    {
        $this->validate($data, $fields);
        $this->setEntityData($entity, $data, $callbacks, false);

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    public function delete(object $entity, bool $hard = false): void
    {
        if(!$hard)
        {
            if(!method_exists($entity, 'setDeleted')) 
            {
                throw new CrudException('The entity does not support soft deletes.');
            } 
    
            $entity->setDeleted(true);
            $this->entityManager->persist($entity);
        }
        else 
        {
            $this->entityManager->remove($entity);
        }

        $this->entityManager->flush();
    }
    
    protected function validate(array $data, array $fields = []): void
    {
        $constraints = new Assert\Collection([
            'fields' => $fields,
            'allowExtraFields' => false
        ]);

        if($errors = $this->validatorService->toArray($constraints, $data))
        {
            throw new CrudException(implode(' | ', $errors));
        }
    }

    protected function setEntityData(object $entity, array $data, array $callbacks, bool $create): void
    {
        foreach($data as $key => $value) 
        {
            $setter = 'set' . ucfirst($key);

            if(method_exists($entity, $setter)) 
            {   
                if(isset($callbacks[$key]) && is_callable($callbacks[$key])) 
                {
                    $value = $callbacks[$key]($value);
                }

                $entity->$setter($value);
            }
        }

        if(method_exists($entity, 'setUpdated')) 
        {
            $entity->setUpdated(new \DateTime());
        }

        if($create && method_exists($entity, 'setCreated')) 
        {
            $entity->setCreated(new \DateTime());
        }
    }
}
