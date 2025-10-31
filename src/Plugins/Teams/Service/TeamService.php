<?php

namespace App\Plugins\Teams\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Teams\Exception\TeamsException;
use App\Service\SlugService;

class TeamService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private SlugService $slugService;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        SlugService $slugService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->slugService = $slugService;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = [], ?callable $callback = null): array
    {
        try {
            return $this->crudManager->findMany(TeamEntity::class, $filters, $page, $limit, $criteria, $callback);
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?TeamEntity
    {
        return $this->crudManager->findOne(TeamEntity::class, $id, $criteria);
    }

    public function create(array $data, ?callable $callback = null): TeamEntity
    {
        $team = new TeamEntity();

        if($callback) {
            $callback($team);
        }

        try {
            if(!isset($data['slug']) || !$data['slug']) {
                $data['slug'] = $data['name'] ?? null;
            }

            $data['slug'] = $this->slugService->generateSlug($data['slug']);

            if($this->getBySlug($data['slug'])) {
                throw new TeamsException('Team slug already exist.');
            }

            // Define constraints for the fields
            $constraints = [
                'name' => [
                    new Assert\NotBlank(['message' => 'Team name is required.']),
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 2, 'max' => 255]),
                ],
                'slug' => [
                    new Assert\NotBlank(['message' => 'Team slug is required.']),
                    new Assert\Type('string'),
                    new Assert\Length(['max' => 255]),
                ],
                'color' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Length(['max' => 50]), 
                ]),
                'description' => new Assert\Optional([
                    new Assert\Type('string'),
                ]),
                'parent_team_id' => new Assert\Optional([
                    new Assert\Type('numeric'),
                ]),
            ];

            // Remove parent_team_id from data as it's handled by the callback
            unset($data['parent_team_id']);

            $this->crudManager->create($team, $data, $constraints);

            return $team;
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    public function update(TeamEntity $team, array $data = []): void
    {
        $constraints = [
            'name' => new Assert\Optional([
                new Assert\Type('string'),
                new Assert\Length(['min' => 2, 'max' => 255]),
            ]),
            'slug' => new Assert\Optional([
                new Assert\Type('string'),
                new Assert\Length(['min' => 2, 'max' => 255]),
            ]),
            'color' => new Assert\Optional([
                new Assert\Type('string'),
                new Assert\Length(['max' => 50]),
            ]),
        ];

        $transform = [
            'slug' => function(string $value) use($team): string {
                $value = $this->slugService->generateSlug($value);

                if($existing = $this->getBySlug($value)) {
                    if($existing->getId() !== $team->getId()) {
                        throw new TeamsException('Team slug already exist.');
                    }
                }

                return $value;
            }
        ];

        try {
            // Handle parent team update
            if(isset($data['parent_team_id'])) {
                if($data['parent_team_id']) {
                    $parentTeam = $this->getOne($data['parent_team_id']);
                    if($parentTeam) {
                        $team->setParentTeam($parentTeam);
                    }
                } else {
                    $team->setParentTeam(null);
                }
                unset($data['parent_team_id']);
            }

            $this->crudManager->update($team, $data, $constraints, $transform);
        } 
        catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    /**
     * Delete team WITHOUT cascade
     * Cascade deletion is handled in the controller to avoid circular dependencies
     */
    public function delete(TeamEntity $team, bool $hard = false): void
    {
        try {
            $this->crudManager->delete($team, $hard);
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    /**
     * Check if team has child teams
     */
    public function hasChildTeams(TeamEntity $team): bool
    {
        $children = $this->entityManager->getRepository(TeamEntity::class)->findBy([
            'parentTeam' => $team,
            'deleted' => false
        ]);
        
        return count($children) > 0;
    }

    /**
     * Get team by slug
     */
    public function getBySlug(string $slug): ?TeamEntity
    {
        $teams = $this->getMany([], 1, 1, ['slug' => $slug, 'deleted' => false]);
        return count($teams) ? $teams[0] : null;
    }

    /**
     * Get all teams for an organization
     */
    public function getTeamsByOrganization(OrganizationEntity $organization): array
    {
        return $this->getMany([], 1, 1000, ['organization' => $organization, 'deleted' => false]);
    }

    /**
     * Get a specific team by ID that belongs to a specific organization
     */
    public function getTeamByIdAndOrganization(int $id, OrganizationEntity $organization): ?TeamEntity
    {
        return $this->getOne($id, ['organization' => $organization]);
    }

    /**
     * Get teams by parent team
     */
    public function getTeamsByParent(TeamEntity $parentTeam): array
    {
        return $this->getMany([], 1, 1000, ['parentTeam' => $parentTeam, 'deleted' => false]);
    }

    /**
     * Get root teams (teams without parent) for an organization
     */
    public function getRootTeamsByOrganization(OrganizationEntity $organization): array
    {
        return $this->entityManager->getRepository(TeamEntity::class)->findBy([
            'organization' => $organization,
            'parentTeam' => null,
            'deleted' => false
        ]);
    }

    /**
     * Check if a team belongs to an organization
     */
    public function teamBelongsToOrganization(TeamEntity $team, OrganizationEntity $organization): bool
    {
        return $team->getOrganization()->getId() === $organization->getId();
    }

    /**
     * Count teams in an organization
     */
    public function countTeamsByOrganization(OrganizationEntity $organization): int
    {
        return count($this->getTeamsByOrganization($organization));
    }

    /**
     * Get all ancestor teams of a team (parent, grandparent, etc.)
     */
    public function getAncestorTeams(TeamEntity $team): array
    {
        $ancestors = [];
        $currentTeam = $team->getParentTeam();
        
        while ($currentTeam !== null) {
            $ancestors[] = $currentTeam;
            $currentTeam = $currentTeam->getParentTeam();
        }
        
        return $ancestors;
    }

    /**
     * Check if a team is a descendant of another team
     */
    public function isDescendantOf(TeamEntity $team, TeamEntity $possibleAncestor): bool
    {
        $currentTeam = $team->getParentTeam();
        
        while ($currentTeam !== null) {
            if ($currentTeam->getId() === $possibleAncestor->getId()) {
                return true;
            }
            $currentTeam = $currentTeam->getParentTeam();
        }
        
        return false;
    }

    /**
     * Get the depth level of a team in the hierarchy (0 for root teams)
     */
    public function getTeamDepth(TeamEntity $team): int
    {
        $depth = 0;
        $currentTeam = $team->getParentTeam();
        
        while ($currentTeam !== null) {
            $depth++;
            $currentTeam = $currentTeam->getParentTeam();
        }
        
        return $depth;
    }
}