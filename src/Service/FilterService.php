<?php

namespace App\Service;

use App\Exception\FilterException;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\MappingException;

class FilterService
{
    private EntityManagerInterface $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function applyAndRun(string $entityClass, array $filters = [], int $page = 1, int $limit = 100, ?callable $callback = null, bool $count = false): array 
    {
        try 
        {
            $this->validateEntity($entityClass);

            $queryBuilder = $this->entityManager->createQueryBuilder();
            
            $queryBuilder->select('t1');
            $queryBuilder->from($entityClass, 't1');
            $queryBuilder->setFirstResult(($page - 1) * $limit);
            $queryBuilder->setMaxResults($limit);

            if($count)
            {
                $queryBuilder->select('COUNT(t1.id)');
            }
            else 
            {
                $queryBuilder->orderBy('t1.id', 'DESC');
            }


            $queryBuilder = $this->apply($queryBuilder, $filters, $entityClass);

            if($callback) 
            {
                $callback($queryBuilder);
            }

            $result = $queryBuilder->getQuery()->getResult();

            if($count)
            {
                return array_values($result[0]);
            }

            return $result;
        } 
        catch(FilterException $e) 
        {
            throw new FilterException($e->getMessage());
        } 
        catch(\Exception $e) 
        {
            throw new \Exception($e->getMessage());
        }
    }

    public function apply(QueryBuilder $queryBuilder, array $filters, string $entityClass): QueryBuilder
    {
        try 
        {
            $this->validateFilters($filters, $entityClass);
            
            foreach($filters as $filter) 
            {
                $this->addConditionToQueryBuilder($queryBuilder, $filter['field'], $filter['operator'], $filter['value']);
            }

            return $queryBuilder;
        }
        catch(FilterException $e) 
        {
            throw new FilterException($e->getMessage());
        } 
        catch(\Exception $e) 
        {
            throw new \Exception($e->getMessage());
        }
    }

    private function addConditionToQueryBuilder(QueryBuilder $queryBuilder, string $field, string $operator, $value): void
    {
        switch ($operator) 
    {
            case 'equals':
                $queryBuilder->andWhere("t1.$field = :$field")->setParameter($field, $value);
                break;
            case 'not_equals':
                $queryBuilder->andWhere("t1.$field != :$field")->setParameter($field, $value);
                break;
            case 'greater_than':
                $queryBuilder->andWhere("t1.$field > :$field")->setParameter($field, $value);
                break;
            case 'less_than':
                $queryBuilder->andWhere("t1.$field < :$field")->setParameter($field, $value);
                break;
            case 'greater_than_or_equal':
                $queryBuilder->andWhere("t1.$field >= :$field")->setParameter($field, $value);
                break;
            case 'less_than_or_equal':
                $queryBuilder->andWhere("t1.$field <= :$field")->setParameter($field, $value);
                break;
            case 'in':
                $queryBuilder->andWhere("t1.$field IN (:$field)")->setParameter($field, $value);
                break;
            case 'not_in':
                $queryBuilder->andWhere("t1.$field NOT IN (:$field)")->setParameter($field, $value);
                break;
            case 'is_null':
                $queryBuilder->andWhere("t1.$field IS NULL");
                break;
            case 'is_not_null':
                $queryBuilder->andWhere("t1.$field IS NOT NULL");
                break;
            case 'between':
                $queryBuilder->andWhere("t1.$field BETWEEN :start AND :end")
                    ->setParameter('start', $value[0])
                    ->setParameter('end', $value[1]);
                break;
            case 'starts_with':
                $queryBuilder->andWhere("LOWER(t1.$field) LIKE :$field")->setParameter($field, strtolower($value) . '%');
                break;
            case 'ends_with':
                $queryBuilder->andWhere("LOWER(t1.$field) LIKE :$field")->setParameter($field, '%' . strtolower($value));
                break;
            case 'contains':
                $queryBuilder->andWhere("LOWER(t1.$field) LIKE :$field")->setParameter($field, '%' . strtolower($value) . '%');
                break;
            case 'empty':
                $queryBuilder->andWhere("(t1.$field IS NULL OR t1.$field = '')");
                break;
            case 'not_empty':
                $queryBuilder->andWhere("(t1.$field IS NOT NULL AND t1.$field != '')");
                break;
            default:
                throw new FilterException("Unsupported operator: '{$operator}'.");
        }
    }

    private function validateFilters(array $filters, string $entityClass): void
    {
        $metadata = $this->entityManager->getClassMetadata($entityClass);

        foreach($filters as $filter) 
        {
            if(!isset($filter['field'], $filter['operator'], $filter['value'])) 
            {
                throw new FilterException("Each filter must contain 'field', 'operator', and 'value' keys.");
            }

            if(!is_string($filter['field']) || !is_string($filter['operator'])) 
            {
                throw new FilterException("Field and operator must be strings.");
            }

            if(!$metadata->hasField($filter['field']) && !$metadata->hasAssociation($filter['field'])) 
            {
                throw new FilterException("Field '" . $filter['field'] . "' does not exist in entity '{$entityClass}'.");
            }

            if($filter['operator'] === 'between') 
            {
                if (!is_array($filter[$field]) || count($filter[$field]) !== 2 || !is_scalar($filter[$field][0]) || !is_scalar($filter[$field][1])) 
                {
                    throw new FilterException("The 'between' operator requires an array with two scalar values.");
                }
            }

            if(in_array($filter['operator'], ['in', 'not_in'], true)) 
            {
                if (!is_array($filter[$field]) || !array_reduce($filter[$field], fn($carry, $item) => $carry && is_scalar($item), true)) 
                {
                    throw new FilterException("The '{$filter['operator']}' operator requires an array of scalar values.");
                }
            }
        }
    }

    private function validateEntity(string $entityClass): void
    {
        try 
        {
            $this->entityManager->getClassMetadata($entityClass);
        } 
        catch (\Exception $e) 
        {
            throw new FilterException("Entity class '{$entityClass}' does not exist.");
        }
    }
}
