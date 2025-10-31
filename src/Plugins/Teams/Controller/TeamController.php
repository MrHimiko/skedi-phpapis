<?php
namespace App\Plugins\Teams\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Teams\Service\TeamService;
use App\Plugins\Teams\Service\UserTeamService;
use App\Plugins\Teams\Service\TeamPermissionService;
use App\Plugins\Teams\Exception\TeamsException;
use App\Plugins\Organizations\Service\UserOrganizationService;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Events\Entity\EventEntity;
use App\Service\CrudManager;
use App\Plugins\Organizations\Service\OrganizationService;


#[Route('/api/organizations/{organization_id}', requirements: ['organization_id' => '\d+'])]
class TeamController extends AbstractController
{
    private ResponseService $responseService;
    private TeamService $teamService;
    private UserTeamService $userTeamService;
    private UserOrganizationService $userOrganizationService;
    private TeamPermissionService $permissionService;
    private EntityManagerInterface $entityManager;
    private CrudManager $crudManager;
    private OrganizationService $organizationService;

    public function __construct(
        ResponseService $responseService,
        TeamService $teamService,
        UserTeamService $userTeamService,
        UserOrganizationService $userOrganizationService,
        TeamPermissionService $permissionService,
        EntityManagerInterface $entityManager,
        CrudManager $crudManager,
        OrganizationService $organizationService 
    ) {
        $this->responseService = $responseService;
        $this->teamService = $teamService;
        $this->userTeamService = $userTeamService;
        $this->userOrganizationService = $userOrganizationService;
        $this->permissionService = $permissionService;
        $this->entityManager = $entityManager;
        $this->crudManager = $crudManager;
        $this->organizationService = $organizationService;
    }

    #[Route('/teams', name: 'teams_get_many#', methods: ['GET'])]
    public function getTeams(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $filters = $request->attributes->get('filters');
        $page = $request->attributes->get('page');
        $limit = $request->attributes->get('limit');

        try 
        {
            // Check if user has access to this organization
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get teams for this organization
            $teams = $this->teamService->getMany($filters, $page, $limit, [
                'organization' => $organization->entity
            ]);
            
            $result = [];
            foreach ($teams as $team) {
                $teamData = $team->toArray();
                
                // Add user's effective role for this team
                $effectiveRole = $this->permissionService->getEffectiveRole($user, $team);
                $teamData['role'] = $effectiveRole;
                $teamData['effective_role'] = $effectiveRole;
                
                $result[] = $teamData;
            }
            
            return $this->responseService->json(true, 'Teams retrieved successfully.', $result);
        } catch (TeamsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Unexpected error occurred.', null, 500);
        }
    }

    #[Route('/teams/{id}', name: 'teams_get_one#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getTeamById(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try 
        {
            // Check if user has access to this organization
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get team by ID ensuring it belongs to the organization
            if(!$team = $this->teamService->getTeamByIdAndOrganization($id, $organization->entity)) {
                return $this->responseService->json(false, 'Team was not found.');
            }
            
            $teamData = $team->toArray();
            
            // Add user's effective role for this team
            $effectiveRole = $this->permissionService->getEffectiveRole($user, $team);
            $teamData['role'] = $effectiveRole;
            $teamData['effective_role'] = $effectiveRole;
            
            return $this->responseService->json(true, 'Team retrieved successfully.', $teamData);
        } catch (TeamsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Unexpected error occurred.', null, 500);
        }
    }

    #[Route('/teams', name: 'teams_create#', methods: ['POST'])]
    public function createTeam(int $organization_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try 
        {
            global $team;

            // Check if user has access to this organization
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Check if parent_team_id is in query parameters
            $parentTeamId = $request->query->get('parent_team_id');
            if ($parentTeamId) {
                $data['parent_team_id'] = $parentTeamId;
            }
            
            // Check permissions based on whether this is a root team or subteam
            if(isset($data['parent_team_id']) && $data['parent_team_id']) {
                // Creating a subteam - check if user can create subteams under the parent
                $parentTeam = $this->teamService->getTeamByIdAndOrganization($data['parent_team_id'], $organization->entity);
                if(!$parentTeam) {
                    return $this->responseService->json(false, 'Parent team not found in this organization.');
                }
                
                if(!$this->permissionService->canCreateSubteam($user, $parentTeam)) {
                    return $this->responseService->json(false, 'You do not have permission to create subteams under this team.');
                }
            } else {
                // Creating a root team - check if user is organization admin
                if(!$this->permissionService->canCreateTeamInOrganization($user, $organization->entity)) {
                    return $this->responseService->json(false, 'You do not have permission to create teams in this organization.');
                }
            }
            
            // Create the team
            $team = $this->teamService->create($data, function($team) use($organization, $data) {
                $team->setOrganization($organization->entity);
                
                // Set parent team if specified
                if(isset($data['parent_team_id']) && $data['parent_team_id']) {
                    $parentTeam = $this->teamService->getOne($data['parent_team_id']);
                    if($parentTeam) {
                        $team->setParentTeam($parentTeam);
                    }
                }
            });

            // Add the creator as admin of the new team
            $userTeam = $this->userTeamService->create([], function($userTeam) use($user, $team) {
                $userTeam->setUser($user);
                $userTeam->setTeam($team);
                $userTeam->setRole('admin');
            });

            // Prepare response with role information
            $response = $team->toArray();
            if ($team->getParentTeam()) {
                $response['parent_team_id'] = $team->getParentTeam()->getId();
            }
            
            // Add role information to response
            $response['role'] = 'admin';
            $response['effective_role'] = 'admin';

            return $this->responseService->json(true, 'Team created successfully.', $response, 201);
        } 
        catch (TeamsException $e)
        {
            global $team;

            if($team?->getId())
            {
                $this->teamService->delete($team, true);
            }

            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/teams/{id}', name: 'teams_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateTeam(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try 
        {
            // Check if user has access to this organization
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get team by ID ensuring it belongs to the organization
            if(!$team = $this->teamService->getTeamByIdAndOrganization($id, $organization->entity)) {
                return $this->responseService->json(false, 'Team was not found.');
            }
            
            // Check if user has permission to edit this team
            if(!$this->permissionService->canEditTeam($user, $team)) {
                return $this->responseService->json(false, 'You do not have permission to update this team.');
            }
            
            // Handle parent_team_id if it's being updated
            if(isset($data['parent_team_id']) && $data['parent_team_id']) {
                // Verify the parent team exists and belongs to this organization
                $newParentTeam = $this->teamService->getTeamByIdAndOrganization($data['parent_team_id'], $organization->entity);
                if(!$newParentTeam) {
                    return $this->responseService->json(false, 'Parent team not found in this organization.');
                }
                
                // Prevent circular nesting
                if($data['parent_team_id'] == $id) {
                    return $this->responseService->json(false, 'A team cannot be its own parent.');
                }
                
                // Check if moving would create a circular reference
                if($this->teamService->isDescendantOf($newParentTeam, $team)) {
                    return $this->responseService->json(false, 'Cannot set a descendant team as parent.');
                }
            }

            $this->teamService->update($team, $data);

            $teamData = $team->toArray();
            
            // Add user's effective role for this team
            $effectiveRole = $this->permissionService->getEffectiveRole($user, $team);
            $teamData['role'] = $effectiveRole;
            $teamData['effective_role'] = $effectiveRole;

            return $this->responseService->json(true, 'Team updated successfully.', $teamData);
        } catch (TeamsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/teams/{id}', name: 'teams_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteTeam(int $organization_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try 
        {
            // Check if user has access to this organization
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get team by ID ensuring it belongs to the organization
            if(!$team = $this->teamService->getTeamByIdAndOrganization($id, $organization->entity)) {
                return $this->responseService->json(false, 'Team was not found.');
            }
            
            // Check if user has permission to delete this team
            if(!$this->permissionService->canEditTeam($user, $team)) {
                return $this->responseService->json(false, 'You do not have permission to delete this team.');
            }
            
            // Handle cascade deletion directly here
            $this->entityManager->beginTransaction();
            
            try {
                $this->cascadeDeleteTeam($team);
                $this->entityManager->flush();
                $this->entityManager->commit();
                
                return $this->responseService->json(true, 'Team deleted successfully.');
            } catch (\Exception $e) {
                $this->entityManager->rollback();
                throw $e;
            }
        } catch (TeamsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Unexpected error occurred.', null, 500);
        }
    }
    
    /**
     * Helper method to cascade delete a team
     */
    private function cascadeDeleteTeam(TeamEntity $team): void
    {
        // Get all child teams
        $childTeams = $this->entityManager->getRepository(TeamEntity::class)->findBy([
            'parentTeam' => $team,
            'deleted' => false
        ]);
        
        // Recursively delete child teams
        foreach ($childTeams as $childTeam) {
            $this->cascadeDeleteTeam($childTeam);
        }
        
        // Delete events for this team
        $events = $this->entityManager->getRepository(EventEntity::class)->findBy([
            'team' => $team,
            'deleted' => false
        ]);
        
        foreach ($events as $event) {
            $event->setDeleted(true);
            $this->entityManager->persist($event);
        }
        
        // Delete the team
        $team->setDeleted(true);
        $this->entityManager->persist($team);
    }





    #[Route('/teams/{team_id}/members', name: 'teams_get_members#', methods: ['GET'], requirements: ['organization_id' => '\d+', 'team_id' => '\d+'])]
    public function getTeamMembers(int $organization_id, int $team_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get team
            $team = $this->teamService->getOne($team_id);
            if (!$team || $team->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Team not found.');
            }
            
            // Get team members
            $members = $this->userTeamService->getMany([], 1, 1000, [
                'team' => $team,
                'deleted' => false
            ]);
            
            $result = [];
            foreach ($members as $member) {
                $result[] = [
                    'id' => $member->getId(),
                    'user' => $member->getUser()->toArray(),
                    'role' => $member->getRole(),
                    'joined' => $member->getCreated()->format('Y-m-d H:i:s')
                ];
            }
            
            return $this->responseService->json(true, 'Team members retrieved successfully.', $result);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to retrieve team members.', null, 500);
        }
    }


    #[Route('/teams/{team_id}/members/{member_id}', name: 'team_members_update#', methods: ['PUT'], requirements: ['team_id' => '\d+', 'member_id' => '\d+'])]
    public function updateTeamMember(int $organization_id, int $team_id, int $member_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            // Get team
            $team = $this->teamService->getOne($team_id);
            if (!$team || $team->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Team not found.');
            }

            // Check if user has admin access
            if (!$this->permissionService->hasAdminAccess($user, $team)) {
                return $this->responseService->json(false, 'You do not have permission to update members.');
            }

            // Get member relationship
            $member = $this->userTeamService->getOne($member_id);
            if (!$member || $member->getTeam()->getId() !== $team_id) {
                return $this->responseService->json(false, 'Member not found.');
            }

            // Update role if provided
            if (isset($data['role']) && in_array($data['role'], ['admin', 'member'])) {
                $member->setRole($data['role']);
                $this->entityManager->persist($member);
                $this->entityManager->flush();
            }

            return $this->responseService->json(true, 'Member updated successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/teams/{team_id}/members/{member_id}', name: 'team_members_remove#', methods: ['DELETE'], requirements: ['team_id' => '\d+', 'member_id' => '\d+'])]
    public function removeTeamMember(int $organization_id, int $team_id, int $member_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get team
            $team = $this->teamService->getOne($team_id);
            if (!$team || $team->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Team not found.');
            }

            // Check if user has admin access
            if (!$this->permissionService->hasAdminAccess($user, $team)) {
                return $this->responseService->json(false, 'You do not have permission to remove members.');
            }

            // Get member relationship
            $member = $this->userTeamService->getOne($member_id);
            if (!$member || $member->getTeam()->getId() !== $team_id) {
                return $this->responseService->json(false, 'Member not found.');
            }

            // Remove member
            $this->userTeamService->delete($member);

            return $this->responseService->json(true, 'Member removed successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/teams/{team_id}/members/leave', name: 'team_leave#', methods: ['POST'], requirements: ['team_id' => '\d+'])]
    public function leaveTeam(int $organization_id, int $team_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get team
            $team = $this->teamService->getOne($team_id);
            if (!$team || $team->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Team not found.');
            }

            // Check if user is member of this team
            $userTeam = $this->userTeamService->isUserInTeam($user, $team);
            if (!$userTeam) {
                return $this->responseService->json(false, 'You are not a member of this team.');
            }

            // Remove user from team (no admin check needed for teams)
            $this->userTeamService->delete($userTeam);

            return $this->responseService->json(true, 'Successfully left the team.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }


    

}