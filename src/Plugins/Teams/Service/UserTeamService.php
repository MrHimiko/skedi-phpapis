<?php

namespace App\Plugins\Teams\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Teams\Entity\UserTeamEntity;
use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Teams\Exception\TeamsException;

class UserTeamService
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
     * Fetch multiple user-team relationships with optional filters.
     */
    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try {
            return $this->crudManager->findMany(
                UserTeamEntity::class,
                $filters,
                $page,
                $limit,
                $criteria
            );
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    /**
     * Fetch a single user-team relationship by ID.
     */
    public function getOne(int $id, array $criteria = []): ?UserTeamEntity
    {
        return $this->crudManager->findOne(UserTeamEntity::class, $id, $criteria);
    }

    /**
     * Soft-delete or hard-delete a user-team relationship.
     */
    public function delete(UserTeamEntity $userTeam, bool $hard = false): void
    {
        try {
            $this->crudManager->delete($userTeam, $hard);
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    public function create(array $data = [], ?callable $callback = null): UserTeamEntity
    {
        try 
        {
            $userTeam = new UserTeamEntity();

            if ($callback) 
            {
                $callback($userTeam);
            }

            if($this->isUserInTeam($userTeam->getUser(), $userTeam->getTeam()))
            {
                throw new TeamsException('User is already connected to team');
            }

            $this->crudManager->create($userTeam, []);

            return $userTeam;
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    /**
     * Update an existing user-team relationship.
     */
    public function update(UserTeamEntity $userTeam, array $data): void
    {
        try {
            // Validate fields
            $this->crudManager->update($userTeam, $data, [
                'role' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\Length(['min' => 2, 'max' => 50]),
                ]),
            ]);

            // If changing user or team, check for duplicate relationships
            if (isset($data['user']) || isset($data['team'])) {
                $user = isset($data['user']) ? $data['user'] : $userTeam->getUser();
                $team = isset($data['team']) ? $data['team'] : $userTeam->getTeam();
                
                $existing = $this->entityManager->getRepository(UserTeamEntity::class)
                    ->findOneBy([
                        'user' => $user,
                        'team' => $team,
                        'deleted' => false
                    ]);

                if ($existing && $existing->getId() !== $userTeam->getId()) {
                    throw new TeamsException("Another relationship already exists between this user and team.");
                }
            }

            // Flush updates
            $this->entityManager->flush();
        } catch (CrudException $e) {
            throw new TeamsException($e->getMessage());
        }
    }

    /**
     * Change a user's role in a team.
     */
    public function changeUserRole($user, TeamEntity $team, string $newRole): void
    {
        $userTeam = $this->entityManager->getRepository(UserTeamEntity::class)
            ->findOneBy([
                'user' => $user,
                'team' => $team,
                'deleted' => false
            ]);
            
        if (!$userTeam) {
            throw new TeamsException("User is not a member of this team.");
        }
        
        $this->update($userTeam, ['role' => $newRole]);
    }

    public function isUserInTeam($user, $team)
    {
        $userTeam = $this->getMany([], 1, 1, [
            'user' => $user,
            'team' => $team
        ]);

        return count($userTeam) ? $userTeam[0] : null;
    }

    public function getTeamByUser(int $id, $user)
    {
        $userTeams = $this->getMany([], 1, 1, [
            'team' => $id,
            'user' => $user
        ]);

        if(!$team = count($userTeams) ? $userTeams[0]->getTeam() : null)
        {
            return null;
        }

        if($team->isDeleted())
        {
            return null;
        }

        return (object) ['entity' => $team, 'role' => $userTeams[0]->getRole()];
    }

    public function getTeamsByUser($user)
    {
        $teams = [];
        $userTeams = $this->getMany([], 1, 1000, [
            'user' => $user
        ]);

        foreach($userTeams as $userTeam)
        {
            $team = $userTeam->getTeam();

            if(!$team->isDeleted())
            {
                $teams[] = (object) ['entity' => $team, 'role' => $userTeam->getRole()];
            }
        }

        return $teams;
    }
}