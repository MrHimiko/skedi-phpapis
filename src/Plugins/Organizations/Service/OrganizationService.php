<?php

namespace App\Plugins\Organizations\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Organizations\Exception\OrganizationsException;
use App\Service\SlugService;
use App\Plugins\Teams\Service\TeamService;
use App\Plugins\Events\Service\EventService;

class OrganizationService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private SlugService $slugService;
    private TeamService $teamService;
    private EventService $eventService;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        SlugService $slugService,
        TeamService $teamService,
        EventService $eventService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->slugService = $slugService;
        $this->teamService = $teamService;
        $this->eventService = $eventService;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = [], ?callable $callback = null): array
    {
        try {
            return $this->crudManager->findMany(OrganizationEntity::class, $filters, $page, $limit, $criteria, $callback);
        } catch (CrudException $e) {
            throw new OrganizationsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?OrganizationEntity
    {
        return $this->crudManager->findOne(OrganizationEntity::class, $id, $criteria);
    }

    public function create(array $data): OrganizationEntity
    {
        $organization = new OrganizationEntity();

        try {
            $data['slug'] = $data['slug'] ?? $data['name'] ?? null;

            if(!$data['slug']) {
                $data['slug'] = $data['name'] ?? null;
            }

            $data['slug'] = $this->slugService->generateSlug($data['slug']);

            if($this->getBySlug($data['slug'])) {
                throw new OrganizationsException('Organization slug already exist.');
            }

            $contraints = [
                'name' => [
                    new Assert\Type('scalar'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                ],
                'slug' => [
                    new Assert\Type('scalar'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                ]
            ];

            $this->crudManager->create($organization, array_intersect_key($data, array_flip(['name', 'slug'])), $contraints);

            $this->update($organization, $data);

            return $organization;
        } 
        catch (CrudException $e) {
            $this->delete($organization, true);
            throw new OrganizationsException($e->getMessage());
        }
    }

    public function update(OrganizationEntity $organization, array $data): void
    {
        $contraints = [
            'name' => new Assert\Optional([
                new Assert\Type('scalar'),
                new Assert\Length(['min' => 2, 'max' => 255]),
            ]),
            'slug' => new Assert\Optional([
                new Assert\NotBlank,
                new Assert\Type('scalar'),
                new Assert\Length(['min' => 2, 'max' => 255]),
            ]),
        ];

        $transform = [
            'slug' => function(string $value) use($organization) {
                $value = $this->slugService->generateSlug($value);

                if($this->getBySlug($value) && $organization->getSlug() !== $value) {
                    throw new OrganizationsException('Slug already exist.');
                }

                return $value;
            }
        ];

        try {
            $this->crudManager->update($organization, $data, $contraints, $transform);
        } 
        catch (CrudException $e) {
            throw new OrganizationsException($e->getMessage());
        }
    }

    /**
     * Delete organization with cascade soft delete
     */
    public function delete(OrganizationEntity $organization, bool $hard = false): void
    {
        try {
            // Simply delete the organization
            // Teams and events will remain but be orphaned
            $this->crudManager->delete($organization, $hard);
        } catch (CrudException $e) {
            throw new OrganizationsException($e->getMessage());
        }
    }

    public function getBySlug(string $slug)
    {
        $organizations = $this->getMany([], 1, 1, ['slug' => $slug, 'deleted' => false]);
        return count($organizations) ? $organizations[0] : null;
    }
}