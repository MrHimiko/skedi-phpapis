<?php

namespace App\Plugins\Organizations\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Organizations\Exception\OrganizationsException;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Events\Entity\EventEntity;
use App\Service\CrudManager;


#[Route('/api')]
class OrganizationController extends AbstractController
{
    private ResponseService $responseService;
    private OrganizationService $organizationService;
    private UserOrganizationService $userOrganizationService;
    private EntityManagerInterface $entityManager;
    private CrudManager $crudManager;

    public function __construct(
        ResponseService $responseService,
        OrganizationService $organizationService,
        UserOrganizationService $userOrganizationService,
        EntityManagerInterface $entityManager,
        CrudManager $crudManager
    ) {
        $this->responseService = $responseService;
        $this->organizationService = $organizationService;
        $this->userOrganizationService = $userOrganizationService;
        $this->entityManager = $entityManager;
        $this->crudManager = $crudManager;
    }

    #[Route('/organizations', name: 'organizations_get_many#', methods: ['GET'])]
    public function getOrganizations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try 
        {
            $organizations = $this->userOrganizationService->getOrganizationsByUser($user);

            $result = [];
            foreach($organizations as $organization)
            {
                $result[] = $organization->entity->toArray();
            }
       
            return $this->responseService->json(true, 'Organizations retrieved successfully.', $result);
        } 
        catch (OrganizationsException $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{id}', name: 'organizations_get_one#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getOrganizationById(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try 
        {
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($id, $user))
            {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            return $this->responseService->json(true, 'Organization retrieved successfully.', $organization->entity->toArray());
        } 
        catch (OrganizationsException $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations', name: 'organizations_create#', methods: ['POST'])]
    public function createOrganization(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try 
        {
            $organization = $this->organizationService->create($data);

            $userOrganization = $this->userOrganizationService->create([], function($userOrganization) use($user, $organization)
            {
                $userOrganization->setUser($user);
                $userOrganization->setOrganization($organization);
                $userOrganization->setRole('admin');
            });

            return $this->responseService->json(true, 'Organization created successfully.', $organization->toArray(), 201);
        } 
        catch (OrganizationsException $e)
        {
            if(isset($organization) && $organization->getId())
            {
                $this->organizationService->delete($organization, true);
            }

            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{id}', name: 'organizations_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateOrganization(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try 
        {
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($id, $user))
            {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            if($organization->role !== 'admin')
            {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            $this->organizationService->update($organization->entity, $data);

            return $this->responseService->json(true, 'Organization updated successfully.', $organization->entity->toArray());
        } 
        catch (OrganizationsException $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/organizations/{id}', name: 'organizations_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteOrganization(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try 
        {
            if(!$organization = $this->userOrganizationService->getOrganizationByUser($id, $user))
            {
                return $this->responseService->json(false, 'Organization was not found.');
            }

            if($organization->role !== 'admin')
            {
                return $this->responseService->json(false, 'You do not have permission to delete this organization.');
            }

            // Handle cascade deletion directly here
            $this->entityManager->beginTransaction();
            
            try {
                // Get all teams in this organization
                $teams = $this->entityManager->getRepository(TeamEntity::class)->findBy([
                    'organization' => $organization->entity,
                    'deleted' => false
                ]);

                // Delete all teams and their events
                foreach ($teams as $team) {
                    $this->cascadeDeleteTeam($team);
                }

                // Get and delete organization-level events (events without a team)
                $orgEvents = $this->entityManager->getRepository(EventEntity::class)->findBy([
                    'organization' => $organization->entity,
                    'team' => null,
                    'deleted' => false
                ]);

                foreach ($orgEvents as $event) {
                    $event->setDeleted(true);
                    $this->entityManager->persist($event);
                }

                // Finally, delete the organization
                $this->organizationService->delete($organization->entity);
                
                $this->entityManager->flush();
                $this->entityManager->commit();

                return $this->responseService->json(true, 'Organization deleted successfully.');
            } catch (\Exception $e) {
                $this->entityManager->rollback();
                throw $e;
            }
        } 
        catch (OrganizationsException $e) 
        {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } 
        catch (\Exception $e) 
        {
            return $this->responseService->json(false, 'An error occurred while deleting the organization.', null, 500);
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


   #[Route('/public/organizations/{slug}', name: 'public_organizations_get_by_slug', methods: ['GET'])]
    public function getPublicOrganizationBySlug(string $slug, Request $request): JsonResponse
    {
        try {
            // Find organization by slug
            $organization = $this->organizationService->getBySlug($slug);

            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.', null, 404);
            }

            // Get ALL teams that belong to this organization (both root and nested)
            $allTeams = [];
            try {
                $allTeams = $this->crudManager->findMany(
                    TeamEntity::class,
                    [],
                    1,
                    100,
                    [
                        'organization' => $organization,
                        'deleted' => false
                    ],
                    function ($queryBuilder) {
                        $queryBuilder->orderBy('t1.name', 'ASC');
                    }
                );
            } catch (\Exception $e) {
                error_log('Error fetching teams: ' . $e->getMessage());
                $allTeams = [];
            }

            // Get ALL events that belong to this organization (both team-assigned and organization-level)
            $allEvents = [];
            try {
                $allEvents = $this->crudManager->findMany(
                    EventEntity::class,
                    [],
                    1,
                    100,
                    [
                        'organization' => $organization,
                        'deleted' => false
                    ],
                    function ($queryBuilder) {
                        $queryBuilder->orderBy('t1.created', 'DESC');
                    }
                );
            } catch (\Exception $e) {
                error_log('Error fetching events: ' . $e->getMessage());
                $allEvents = [];
            }

            // Format response with limited public data INCLUDING parent team info
            $response = [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'slug' => $organization->getSlug(),
                'teams' => array_map(function($team) {
                    return [
                        'id' => $team->getId(),
                        'name' => $team->getName(),
                        'slug' => $team->getSlug(),
                        'parent_team_id' => $team->getParentTeam() ? $team->getParentTeam()->getId() : null,  // ADD THIS
                        'parent_team' => $team->getParentTeam() ? [  // ADD THIS
                            'id' => $team->getParentTeam()->getId(),
                            'name' => $team->getParentTeam()->getName(),
                            'slug' => $team->getParentTeam()->getSlug()
                        ] : null
                    ];
                }, $allTeams),
                'events' => array_map(function($event) {
                    return [
                        'id' => $event->getId(),
                        'name' => $event->getName(),
                        'slug' => $event->getSlug(),
                        'duration' => $event->getDuration(),
                        'team_id' => $event->getTeam() ? $event->getTeam()->getId() : null,
                        'team_name' => $event->getTeam() ? $event->getTeam()->getName() : null,
                        'created_at' => $event->getCreated() ? $event->getCreated()->format('Y-m-d H:i:s') : null
                    ];
                }, $allEvents)
            ];

            return $this->responseService->json(true, 'Organization retrieved successfully.', $response);

        } catch (OrganizationsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            // Don't expose internal errors to public API
            error_log('Public organization API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            return $this->responseService->json(false, 'An error occurred while retrieving organization data.', null, 500);
        }
    }



#[Route('/public/teams/{orgSlug}/{teamSlug}', name: 'public_teams_get_by_slug', methods: ['GET'])]
    public function getPublicTeamBySlug(string $orgSlug, string $teamSlug, Request $request): JsonResponse
    {
        try {
            // Find organization by slug
            $organization = $this->organizationService->getBySlug($orgSlug);

            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.', null, 404);
            }

            // Find team by slug within organization
            $teams = [];
            try {
                $teams = $this->crudManager->findMany(
                    TeamEntity::class,
                    [],
                    1,
                    1,
                    [
                        'slug' => $teamSlug,
                        'organization' => $organization,
                        'deleted' => false
                    ]
                );
            } catch (\Exception $e) {
                error_log('Error fetching team: ' . $e->getMessage());
                return $this->responseService->json(false, 'Team not found.', null, 404);
            }

            if (empty($teams)) {
                return $this->responseService->json(false, 'Team not found.', null, 404);
            }

            $team = $teams[0]; // Get the first (and only) result

            // Get ALL descendant teams (children, grandchildren, etc.) recursively
            $allSubTeams = [];
            try {
                $allSubTeams = $this->getAllDescendantTeams($team);
            } catch (\Exception $e) {
                error_log('Error fetching sub-teams for team ' . $team->getId() . ': ' . $e->getMessage());
                $allSubTeams = [];
            }

            // Get team events
            $teamEvents = [];
            try {
                $teamEvents = $this->crudManager->findMany(
                    EventEntity::class,
                    [],
                    1,
                    100,
                    [
                        'team' => $team,
                        'deleted' => false
                    ],
                    function ($queryBuilder) {
                        $queryBuilder->orderBy('t1.created', 'DESC');
                    }
                );
            } catch (\Exception $e) {
                error_log('Error fetching events for team ' . $team->getId() . ': ' . $e->getMessage());
                $teamEvents = [];
            }

            // Format response with complete hierarchy data
            $response = [
                'id' => $team->getId(),
                'name' => $team->getName(),
                'slug' => $team->getSlug(),

                'organization' => [
                    'id' => $organization->getId(),
                    'name' => $organization->getName(),
                    'slug' => $organization->getSlug()
                ],
                'parent_team' => $team->getParentTeam() ? [
                    'id' => $team->getParentTeam()->getId(),
                    'name' => $team->getParentTeam()->getName(),
                    'slug' => $team->getParentTeam()->getSlug()
                ] : null,
                'sub_teams' => array_map(function($subTeam) {
                    return [
                        'id' => $subTeam->getId(),
                        'name' => $subTeam->getName(),
                        'slug' => $subTeam->getSlug(),

                        'parent_team_id' => $subTeam->getParentTeam() ? $subTeam->getParentTeam()->getId() : null,
                        'parent_team' => $subTeam->getParentTeam() ? [
                            'id' => $subTeam->getParentTeam()->getId(),
                            'name' => $subTeam->getParentTeam()->getName(),
                            'slug' => $subTeam->getParentTeam()->getSlug()
                        ] : null
                    ];
                }, $allSubTeams),
                'events' => array_map(function($event) {
                    return [
                        'id' => $event->getId(),
                        'name' => $event->getName(),
                        'slug' => $event->getSlug(),
                        'description' => $event->getDescription(),
                        'duration' => $event->getDuration(),
                        'created_at' => $event->getCreated() ? $event->getCreated()->format('Y-m-d H:i:s') : null
                    ];
                }, $teamEvents)
            ];

            return $this->responseService->json(true, 'Team retrieved successfully.', $response);

        } catch (\Exception $e) {
            // Don't expose internal errors to public API
            error_log('Public team API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            return $this->responseService->json(false, 'An error occurred while retrieving team data.', null, 500);
        }
    }

    /**
     * Helper method to recursively get all descendant teams
     */
    private function getAllDescendantTeams(TeamEntity $parentTeam): array
    {
        $allDescendants = [];
        
        try {
            // Get direct children
            $directChildren = $this->crudManager->findMany(
                TeamEntity::class,
                [],
                1,
                100,
                [
                    'parentTeam' => $parentTeam,
                    'deleted' => false
                ],
                function ($queryBuilder) {
                    $queryBuilder->orderBy('t1.name', 'ASC');
                }
            );

            foreach ($directChildren as $child) {
                // Add the child
                $allDescendants[] = $child;
                
                // Recursively get grandchildren, great-grandchildren, etc.
                $grandChildren = $this->getAllDescendantTeams($child);
                $allDescendants = array_merge($allDescendants, $grandChildren);
            }

        } catch (\Exception $e) {
            error_log('Error fetching descendant teams for team ' . $parentTeam->getId() . ': ' . $e->getMessage());
        }

        return $allDescendants;
    }
    
}