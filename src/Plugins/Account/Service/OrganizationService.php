<?php

namespace App\Plugins\Account\Service;

use App\Plugins\Account\Entity\OrganizationEntity;
use App\Plugins\Account\Repository\OrganizationRepository;

use App\Plugins\Account\Exception\AccountException;

use App\Service\CrudManager;
use App\Exception\CrudException;

use Symfony\Component\Validator\Constraints as Assert;

class OrganizationService
{
    private CrudManager $crudManager;
    private OrganizationRepository $organizationRepository;

    public function __construct(
        CrudManager $crudManager,
        OrganizationRepository $organizationRepository
    ) {
        $this->crudManager = $crudManager;
        $this->organizationRepository = $organizationRepository;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try 
        {
            return $this->crudManager->findMany(OrganizationEntity::class, $filters, $page, $limit, $criteria);
        } 
        catch (CrudException $e) 
        {
            throw new AccountException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?OrganizationEntity
    {
        // Merge in default criteria such as "deleted => false" if desired
        return $this->crudManager->findOne(
            OrganizationEntity::class,
            $id,
            $criteria + ['deleted' => false]
        );
    }

    public function delete(OrganizationEntity $organization): void
    {
        try {
            $this->crudManager->delete($organization);
        } catch (CrudException $e) {
            throw new AccountException($e->getMessage());
        }
    }

    public function update(OrganizationEntity $organization, array $data = []): void
    {
        try {
            $this->crudManager->update(
                $organization,
                $data,
                [
                    // Example constraints; adjust to match your OrganizationEntity fields.
                    'name' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['min' => 2, 'max' => 255]),
                    ]),
                    'role' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 255]),
                    ]),
                ],
                $this->callbacks($organization)
            );
        } catch (CrudException $e) {
            throw new AccountException($e->getMessage());
        }
    }

    public function create(array $data = []): OrganizationEntity
    {
        try {
            $organization = new OrganizationEntity();

            $this->crudManager->create(
                $organization,
                $data,
                [
                    // Example constraints; adjust to match your OrganizationEntity fields.
                    'name' => [
                        new Assert\NotBlank(),
                        new Assert\Type('string'),
                        new Assert\Length(['min' => 2, 'max' => 255]),
                    ],
                    'role' => new Assert\Optional([
                        new Assert\Type('string'),
                        new Assert\Length(['max' => 255]),
                    ]),
                ],
                $this->callbacks($organization)
            );

            return $organization;
        } catch (CrudException $e) {
            throw new AccountException($e->getMessage());
        }
    }
}
