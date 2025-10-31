<?php

namespace App\Plugins\Teams\Service;

use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Teams\Service\TeamService;
use App\Plugins\Teams\Service\UserTeamService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use Doctrine\ORM\EntityManagerInterface;

class TeamPermissionService
{
    private UserTeamService $userTeamService;
    private UserOrganizationService $userOrganizationService;
    private TeamService $teamService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        UserTeamService $userTeamService,
        UserOrganizationService $userOrganizationService,
        TeamService $teamService,
        EntityManagerInterface $entityManager
    ) {
        $this->userTeamService = $userTeamService;
        $this->userOrganizationService = $userOrganizationService;
        $this->teamService = $teamService;
        $this->entityManager = $entityManager;
    }

    /**
     * Check if user has admin access to a team
     * This includes:
     * 1. Direct admin role on the team
     * 2. Admin role on the organization
     * 3. Admin role on any parent team
     */
    public function hasAdminAccess(UserEntity $user, TeamEntity $team): bool
    {
        // 1. Check if user is organization admin
        $organization = $team->getOrganization();
        $userOrg = $this->userOrganizationService->getOrganizationByUser($organization->getId(), $user);
        if ($userOrg && $userOrg->role === 'admin') {
            return true;
        }

        // 2. Check direct team membership
        $userTeam = $this->userTeamService->isUserInTeam($user, $team);
        if ($userTeam && $userTeam->getRole() === 'admin') {
            return true;
        }

        // 3. Check parent team hierarchy
        $parentTeam = $team->getParentTeam();
        while ($parentTeam !== null) {
            $parentUserTeam = $this->userTeamService->isUserInTeam($user, $parentTeam);
            if ($parentUserTeam && $parentUserTeam->getRole() === 'admin') {
                return true;
            }
            $parentTeam = $parentTeam->getParentTeam();
        }

        return false;
    }

    /**
     * Get the effective role of a user for a team
     * Returns 'admin' if user has admin access through any means
     * Returns 'member' if user has member access
     * Returns null if user has no access
     */
    public function getEffectiveRole(UserEntity $user, TeamEntity $team): ?string
    {
        // Check admin access first (includes org admin and parent team admin)
        if ($this->hasAdminAccess($user, $team)) {
            return 'admin';
        }

        // Check direct membership
        $userTeam = $this->userTeamService->isUserInTeam($user, $team);
        if ($userTeam) {
            return $userTeam->getRole();
        }

        // Check if user is organization member (gives member access to all teams)
        $organization = $team->getOrganization();
        $userOrg = $this->userOrganizationService->getOrganizationByUser($organization->getId(), $user);
        if ($userOrg) {
            return 'member';
        }

        return null;
    }

    /**
     * Check if user can create subteams
     * User must be admin of the parent team or organization
     */
    public function canCreateSubteam(UserEntity $user, TeamEntity $parentTeam): bool
    {
        return $this->hasAdminAccess($user, $parentTeam);
    }

    /**
     * Check if user can edit/delete a team
     */
    public function canEditTeam(UserEntity $user, TeamEntity $team): bool
    {
        return $this->hasAdminAccess($user, $team);
    }

    /**
     * Check if user can create teams in an organization
     * User must be organization admin
     */
    public function canCreateTeamInOrganization(UserEntity $user, OrganizationEntity $organization): bool
    {
        $userOrg = $this->userOrganizationService->getOrganizationByUser($organization->getId(), $user);
        return $userOrg && $userOrg->role === 'admin';
    }
}