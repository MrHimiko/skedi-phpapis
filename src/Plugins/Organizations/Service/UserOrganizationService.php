<?php

namespace App\Plugins\Organizations\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Organizations\Entity\UserOrganizationEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Organizations\Exception\OrganizationsException;

class UserOrganizationService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager
    ) {
        $this->crudManager   = $crudManager;
        $this->entityManager = $entityManager;
    }

    /**
     * Fetch multiple user-organization relationships with optional filters.
     */
    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try {
            return $this->crudManager->findMany(
                UserOrganizationEntity::class,
                $filters,
                $page,
                $limit,
                $criteria
            );
        } catch (CrudException $e) {
            throw new OrganizationsException($e->getMessage());
        }
    }

    /**
     * Fetch a single user-organization relationship by ID.
     */
    public function getOne(int $id, array $criteria = []): ?UserOrganizationEntity
    {
        return $this->crudManager->findOne(UserOrganizationEntity::class, $id, $criteria);
    }

   


    /**
     * Soft-delete or hard-delete a user-organization relationship.
     */
    public function delete(UserOrganizationEntity $userOrganization, bool $hard = false): void
    {
        try {
            $this->crudManager->delete($userOrganization, $hard);
        } catch (CrudException $e) {
            throw new OrganizationsException($e->getMessage());
        }
    }

    public function create(array $data = [], ?callable $callback = null): UserOrganizationEntity
    {
        try 
        {
            $userOrganization = new UserOrganizationEntity();

            if ($callback) 
            {
                $callback($userOrganization);
            }

            if($this->isUserInOrganization($userOrganization->getUser(), $userOrganization->getOrganization()))
            {
                throw new OrganizationsException('User is already connected to organization');
            }

            $this->crudManager->create($userOrganization, []);

            return $userOrganization;
        } catch (CrudException $e) {
            throw new OrganizationsException($e->getMessage());
        }
    }

    /**
     * Update an existing user-organization relationship.
     */
    public function update(UserOrganizationEntity $userOrganization, array $data): void
    {
        try {
            // Validate fields
            $this->crudManager->update($userOrganization, $data, [
                'role' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 2, 'max' => 50]),
                ]),
            ]);

            // If changing user or organization, check for duplicate relationships
            if (isset($data['user']) || isset($data['organization'])) {
                $user = isset($data['user']) ? $data['user'] : $userOrganization->getUser();
                $organization = isset($data['organization']) ? $data['organization'] : $userOrganization->getOrganization();
                
                $existing = $this->entityManager->getRepository(UserOrganizationEntity::class)
                    ->findOneBy([
                        'user' => $user,
                        'organization' => $organization,
                        'deleted' => false
                    ]);

                if ($existing && $existing->getId() !== $userOrganization->getId()) {
                    throw new OrganizationsException("Another relationship already exists between this user and organization.");
                }
            }

            // Flush updates
            $this->entityManager->flush();
        } catch (CrudException $e) {
            throw new OrganizationsException($e->getMessage());
        }
    }

    /**
     * Change a user's role in an organization.
     */
    public function changeUserRole($user, OrganizationEntity $organization, string $newRole): void
    {
        $userOrganization = $this->entityManager->getRepository(UserOrganizationEntity::class)
            ->findOneBy([
                'user' => $user,
                'organization' => $organization,
                'deleted' => false
            ]);
            
        if (!$userOrganization) {
            throw new OrganizationsException("User is not a member of this organization.");
        }
        
        $this->update($userOrganization, ['role' => $newRole]);
    }

    public function isUserInOrganization($user, $organization)
    {
        $userOrganization = $this->getMany([], 1, 1, [
            'user' => $user,
            'organization' => $organization
        ]);

        return count($userOrganization) ? $userOrganization[0] : null;
    }

    public function getOrganizationByUser(int $id, $user)
    {
        $userOrganizations = $this->getMany([], 1, 1, [
            'organization' => $id,
            'user' => $user
        ]);

        if(!$organization = count($userOrganizations) ? $userOrganizations[0]->getOrganization() : null)
        {
            return null;
        }

        if($organization->isDeleted())
        {
            return null;
        }

        return (object) ['entity' => $organization, 'role' => $userOrganizations[0]->getRole()];
    }

    public function getOrganizationsByUser($user)
    {
        $organizations = [];
        $userOrganizations = $this->getMany([], 1, 1000, [
            'user' => $user
        ]);

        foreach($userOrganizations as $userOrganization)
        {
            $organization = $userOrganization->getOrganization();

            if(!$organization->isDeleted())
            {
                $organizations[] = (object) ['entity' => $organization, 'role' => $userOrganization->getRole()];
            }
        }

        return $organizations;
    }


    /**
     * Get all members of an organization
     * 
     */
    public function getMembersByOrganization(int $organizationId): array
    {
        try {
            // Use the existing getMany method with organization filter
            $userOrganizations = $this->getMany([], 1, 1000, [
                'organization' => $organizationId
            ]);
            
            // Since the entity doesn't have a deleted field, check if the organization itself is deleted
            $members = [];
            foreach ($userOrganizations as $userOrganization) {
                $organization = $userOrganization->getOrganization();
                
                if (!$organization->isDeleted()) {
                    $members[] = $userOrganization;
                }
            }
            
            return $members;
        } catch (CrudException $e) {
            throw new OrganizationsException($e->getMessage());
        }
    }

}