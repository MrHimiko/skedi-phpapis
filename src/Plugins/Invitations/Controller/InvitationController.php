<?php

namespace App\Plugins\Invitations\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Invitations\Service\InvitationService;
use App\Plugins\Invitations\Exception\InvitationsException;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Teams\Service\TeamService;
use App\Plugins\Teams\Service\UserTeamService;
use App\Plugins\Teams\Service\TeamPermissionService;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api')]
class InvitationController extends AbstractController
{
    private ResponseService $responseService;
    private InvitationService $invitationService;
    private OrganizationService $organizationService;
    private UserOrganizationService $userOrganizationService;
    private TeamService $teamService;
    private UserTeamService $userTeamService;
    private TeamPermissionService $permissionService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        ResponseService $responseService,
        InvitationService $invitationService,
        OrganizationService $organizationService,
        UserOrganizationService $userOrganizationService,
        TeamService $teamService,
        UserTeamService $userTeamService,
        EntityManagerInterface $entityManager,
        TeamPermissionService $permissionService
    ) {
        $this->responseService = $responseService;
        $this->invitationService = $invitationService;
        $this->organizationService = $organizationService;
        $this->userOrganizationService = $userOrganizationService;
        $this->teamService = $teamService;
        $this->userTeamService = $userTeamService;
        $this->entityManager = $entityManager;
        $this->permissionService = $permissionService;
    }


    #[Route('/invitations/send', name: 'invitations_send#', methods: ['POST'])]
    public function sendInvitation(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');

        try {
            // Validate required fields
            if (empty($data['email'])) {
                return $this->responseService->json(false, 'Email is required.');
            }

            if (empty($data['organization_id'])) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }

            // Get organization
            $organization = $this->organizationService->getOne($data['organization_id']);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.');
            }

            // Check if user is admin of organization
            $userOrg = $this->userOrganizationService->getOrganizationByUser($organization->getId(), $user);
            if (!$userOrg || $userOrg->role !== 'admin') {
                return $this->responseService->json(false, 'Only administrators can send invitations.');
            }

            // Get team if specified
            $team = null;
            if (!empty($data['team_id'])) {
                $team = $this->teamService->getOne($data['team_id']);
                if (!$team || $team->getOrganization()->getId() !== $organization->getId()) {
                    return $this->responseService->json(false, 'Team not found.');
                }

                // Check if user is admin of the team
                $effectiveRole = $this->permissionService->getEffectiveRole($user, $team);
                if ($effectiveRole !== 'admin') {
                    return $this->responseService->json(false, 'Only team administrators can send invitations.');
                }
            }

            // Send invitation
            $invitation = $this->invitationService->sendInvitation(
                $data['email'],
                $user,
                $organization,
                $team,
                $data['role'] ?? 'member'
            );

            return $this->responseService->json(true, 'Invitation sent successfully.', $invitation->toArray());
        } catch (InvitationsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred while sending the invitation.', null, 500);
        }
    }

    #[Route('/invitations/pending', name: 'invitations_pending#', methods: ['GET'])]
    public function getPendingInvitations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $invitations = $this->invitationService->getPendingInvitationsByEmail($user->getEmail());
            
            $result = array_map(function($invitation) {
                return $invitation->toArray();
            }, $invitations);

            return $this->responseService->json(true, 'Pending invitations retrieved.', $result);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to retrieve invitations.', null, 500);
        }
    }

    #[Route('/invitations/sent', name: 'invitations_sent#', methods: ['GET'])]
    public function getSentInvitations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $organizationId = $request->query->get('organization_id');
        $teamId = $request->query->get('team_id');

        try {
            // Get all invitations sent by user
            $allInvitations = $this->invitationService->getInvitationsSentByUser($user);
            
            // Filter invitations based on query parameters
            $filteredInvitations = [];
            
            foreach ($allInvitations as $invitation) {
                // Skip if deleted
                if ($invitation->isDeleted()) {
                    continue;
                }
                
                // Filter by organization if specified
                if ($organizationId && $invitation->getOrganization()->getId() != $organizationId) {
                    continue;
                }
                
                // Filter by team if specified
                if ($teamId) {
                    // If team_id is provided but invitation has no team, skip it
                    if (!$invitation->getTeam()) {
                        continue;
                    }
                    // If team_id doesn't match, skip it
                    if ($invitation->getTeam()->getId() != $teamId) {
                        continue;
                    }
                }
                
                $filteredInvitations[] = $invitation;
            }
            
            // Convert to array
            $result = array_map(function($invitation) {
                return $invitation->toArray();
            }, $filteredInvitations);

            return $this->responseService->json(true, 'Sent invitations retrieved.', $result);
        } catch (\Exception $e) {
            // Log the actual error for debugging
            error_log('Error in getSentInvitations: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $this->responseService->json(false, 'Failed to retrieve invitations: ' . $e->getMessage(), null, 500);
        }
    }

    #[Route('/invitations/{id}/accept', name: 'invitations_accept#', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function acceptInvitation(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $invitation = $this->invitationService->getOne($id);
            if (!$invitation) {
                return $this->responseService->json(false, 'Invitation not found.');
            }

            $this->invitationService->acceptInvitation($invitation, $user);

            return $this->responseService->json(true, 'Invitation accepted successfully.');
        } catch (InvitationsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\App\Plugins\Organizations\Exception\OrganizationsException $e) {
            // Catch organization-specific exceptions and return the actual error message
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\App\Plugins\Teams\Exception\TeamsException $e) {
            // Catch team-specific exceptions and return the actual error message
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            // Only use generic message for unexpected errors
            return $this->responseService->json(false, 'An unexpected error occurred.', null, 500);
        }
    }


    #[Route('/invitations/{id}/decline', name: 'invitations_decline#', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function declineInvitation(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $invitation = $this->invitationService->getOne($id);
            
            if (!$invitation) {
                return $this->responseService->json(false, 'Invitation not found.');
            }
            
            if ($invitation->getEmail() !== $user->getEmail()) {
                return $this->responseService->json(false, 'You cannot decline this invitation.');
            }
            
            // Instead of using the service method, update directly
            $invitation->setStatus('declined');
            $this->entityManager->persist($invitation);
            $this->entityManager->flush();

            return $this->responseService->json(true, 'Invitation declined.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to decline invitation: ' . $e->getMessage(), null, 500);
        }
    }


    #[Route('/invitations/{id}/resend', name: 'invitations_resend#', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resendInvitation(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $invitation = $this->invitationService->getOne($id);
            if (!$invitation) {
                return $this->responseService->json(false, 'Invitation not found.');
            }

            // Check if user has permission to resend
            if ($invitation->getInvitedBy()->getId() !== $user->getId()) {
                // Check if user is admin of the organization
                $userOrg = $this->userOrganizationService->getOrganizationByUser(
                    $invitation->getOrganization()->getId(), 
                    $user
                );
                if (!$userOrg || $userOrg->role !== 'admin') {
                    return $this->responseService->json(false, 'You do not have permission to resend this invitation.');
                }
            }

            $this->invitationService->resendInvitation($invitation);

            return $this->responseService->json(true, 'Invitation resent successfully.');
        } catch (InvitationsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to resend invitation.', null, 500);
        }
    }

    #[Route('/invitations/{id}', name: 'invitations_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteInvitation(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $invitation = $this->invitationService->getOne($id);
            if (!$invitation) {
                return $this->responseService->json(false, 'Invitation not found.');
            }

            // Check permission
            if ($invitation->getInvitedBy()->getId() !== $user->getId()) {
                $userOrg = $this->userOrganizationService->getOrganizationByUser(
                    $invitation->getOrganization()->getId(), 
                    $user
                );
                if (!$userOrg || $userOrg->role !== 'admin') {
                    return $this->responseService->json(false, 'You do not have permission to delete this invitation.');
                }
            }

            $this->invitationService->delete($invitation);

            return $this->responseService->json(true, 'Invitation deleted successfully.');
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to delete invitation.', null, 500);
        }
    }
}