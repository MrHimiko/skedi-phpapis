<?php

namespace App\Plugins\Invitations\Service;

use App\Service\CrudManager;
use App\Plugins\Invitations\Entity\InvitationEntity;
use App\Plugins\Invitations\Exception\InvitationsException;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Teams\Entity\TeamEntity;
use App\Plugins\Email\Service\EmailService;
use App\Plugins\Account\Service\UserService;
use App\Plugins\Teams\Service\UserTeamService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

class InvitationService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private EmailService $emailService;
    private UserService $userService;
    private UserTeamService $userTeamService;
    private UserOrganizationService $userOrganizationService;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        EmailService $emailService,
        UserService $userService,
        UserTeamService $userTeamService,
        UserOrganizationService $userOrganizationService
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->emailService = $emailService;
        $this->userService = $userService;
        $this->userTeamService = $userTeamService;
        $this->userOrganizationService = $userOrganizationService;
    }

    /**
     * Create a new invitation
    */
    public function create(array $data): InvitationEntity
    {
        // Validate email
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvitationsException('Valid email address is required.');
        }
        
        // Validate role
        if (!in_array($data['role'], ['admin', 'member'])) {
            throw new InvitationsException('Invalid role. Must be admin or member.');
        }
        
        // Create new entity instance
        $invitation = new InvitationEntity();
        
        // Set all fields
        $invitation->setEmail($data['email']);
        $invitation->setInvitedBy($data['invited_by']);
        $invitation->setOrganization($data['organization']);
        if (isset($data['team']) && $data['team'] !== null) {
            $invitation->setTeam($data['team']);
        }
        $invitation->setRole($data['role']);
        $invitation->setToken($data['token']);
        $invitation->setStatus($data['status']);
        
        // Persist and flush
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
        
        return $invitation;
    }

    /**
     * Send invitation for organization or team
     */
    public function sendInvitation(
        string $email,
        UserEntity $invitedBy,
        OrganizationEntity $organization,
        ?TeamEntity $team = null,
        string $role = 'member'
    ): InvitationEntity {
        // Check if user already exists globally
        $userRepository = $this->entityManager->getRepository(UserEntity::class);
        $existingUser = $userRepository->findOneBy([
            'email' => $email,
            'deleted' => false
        ]);
        
        if ($existingUser) {
            if ($team) {
                // THIS IS A TEAM INVITATION
                // Only check if user is already in THIS specific team
                if ($this->userTeamService->isUserInTeam($existingUser, $team)) {
                    throw new InvitationsException('User is already a member of this team.');
                }
                // Don't check organization membership for team invitations
                // User might already be in org but not in this team, which is fine
            } else {
                // THIS IS AN ORGANIZATION INVITATION
                // Check if user is already in organization
                if ($this->userOrganizationService->getOrganizationByUser($organization->getId(), $existingUser)) {
                    throw new InvitationsException('User is already a member of this organization.');
                }
            }
        }

        // Check for existing pending invitation
        $existingInvitation = $this->entityManager->getRepository(InvitationEntity::class)
            ->findOneBy([
                'email' => $email,
                'organization' => $organization,
                'team' => $team,
                'status' => 'pending',
                'deleted' => false
            ]);

        if ($existingInvitation) {
            throw new InvitationsException('An invitation has already been sent to this email.');
        }

        // Create invitation
        $invitation = $this->create([
            'email' => $email,
            'invited_by' => $invitedBy,
            'organization' => $organization,
            'team' => $team,
            'role' => $role,
            'status' => 'pending',
            'token' => bin2hex(random_bytes(32))
        ]);

        // Send email notification (disabled for now)
        $this->sendInvitationEmail($invitation);

        return $invitation;
    }


    /**
     * Send invitation email
     */
    private function sendInvitationEmail(InvitationEntity $invitation): void
    {

        return;
        $targetName = $invitation->getTeam() 
            ? $invitation->getTeam()->getName() . ' team'
            : $invitation->getOrganization()->getName() . ' organization';

        // Use a default URL if FRONTEND_URL is not set
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';

        $emailData = [
            'to' => $invitation->getEmail(),
            'template_id' => 'd-invitation', // SendGrid template ID
            'dynamic_template_data' => [
                'invited_by_name' => $invitation->getInvitedBy()->getName(),
                'invited_by_email' => $invitation->getInvitedBy()->getEmail(),
                'organization_name' => $invitation->getOrganization()->getName(),
                'team_name' => $invitation->getTeam()?->getName(),
                'target_name' => $targetName,
                'role' => $invitation->getRole(),
                'action_url' => $frontendUrl . '/login'
            ]
        ];

        $this->emailService->send($emailData);
    }

    /**
     * Get pending invitations for an email
     */
    public function getPendingInvitationsByEmail(string $email): array
    {
        $repository = $this->entityManager->getRepository(InvitationEntity::class);
        
        return $repository->findBy([
            'email' => $email,
            'status' => 'pending',
            'deleted' => false
        ]);
    }

    /**
     * Get invitations sent by a user
     */
    public function getInvitationsSentByUser(UserEntity $user): array
    {
        $repository = $this->entityManager->getRepository(InvitationEntity::class);
        
        // We need to find all invitations where invitedBy = user
        $invitations = $repository->findBy([
            'deleted' => false
        ]);
        
        // Filter by invitedBy manually since findBy might not work with relations
        $userInvitations = [];
        foreach ($invitations as $invitation) {
            if ($invitation->getInvitedBy()->getId() === $user->getId()) {
                $userInvitations[] = $invitation;
            }
        }
        
        return $userInvitations;
    }

    

    /**
     * Accept invitation
     */
    public function acceptInvitation(InvitationEntity $invitation, UserEntity $user): void
    {
        if ($invitation->getStatus() !== 'pending') {
            throw new InvitationsException('This invitation is no longer valid.');
        }

        if ($invitation->getEmail() !== $user->getEmail()) {
            throw new InvitationsException('This invitation is for a different email address.');
        }

        // Map our role names to what the database expects
        $roleMapping = [
            'admin' => 'admin',
            'member' => 'member'  
        ];
        
        // Determine organization role
        $invitationRole = $invitation->getTeam() ? 'member' : $invitation->getRole();
        $dbRole = isset($roleMapping[$invitationRole]) ? $roleMapping[$invitationRole] : 'member';
        
        // Map team role as well - THIS WAS THE MISSING PIECE!
        $teamDbRole = isset($roleMapping[$invitation->getRole()]) ? $roleMapping[$invitation->getRole()] : 'member';

        // Determine what the user is being invited to
        $invitationType = $invitation->getTeam() ? 'team' : 'organization';
        $invitationName = $invitation->getTeam() ? $invitation->getTeam()->getName() : $invitation->getOrganization()->getName();

        try {
            // Add user to organization using the callback pattern
            $this->userOrganizationService->create([], function($userOrganization) use ($user, $invitation, $dbRole) {
                $userOrganization->setUser($user);
                $userOrganization->setOrganization($invitation->getOrganization());
                $userOrganization->setRole($dbRole);
            });
        } catch (\App\Plugins\Organizations\Exception\OrganizationsException $e) {
            // If user is already in organization, handle based on invitation type
            if (strpos($e->getMessage(), 'already connected to organization') !== false) {
                if ($invitation->getTeam()) {
                    // This is a team invitation, but user is already in the org, so try to add to team
                    // Don't throw error here, continue to team addition below
                } else {
                    // This is an org invitation and user is already in org
                    $invitation->setStatus('accepted');
                    $invitation->setAcceptedAt(new \DateTime());
                    $this->entityManager->flush();
                    
                    throw new InvitationsException("You are already a member of this {$invitationType}. The invitation has been removed.");
                }
            } else {
                throw $e;
            }
        }

        // Add user to team if specified - also using callback pattern
        if ($invitation->getTeam()) {
            try {
                $this->userTeamService->create([], function($userTeam) use ($user, $invitation, $teamDbRole) {
                    $userTeam->setUser($user);
                    $userTeam->setTeam($invitation->getTeam());
                    $userTeam->setRole($teamDbRole); // <- FIXED: Now uses mapped role instead of raw role
                });
            } catch (\App\Plugins\Teams\Exception\TeamsException $e) {
                // If user is already in team
                if (strpos($e->getMessage(), 'already connected to team') !== false) {
                    // Mark invitation as accepted anyway
                    $invitation->setStatus('accepted');
                    $invitation->setAcceptedAt(new \DateTime());
                    $this->entityManager->flush();
                    
                    throw new InvitationsException("You are already a member of this {$invitationType}. The invitation has been removed.");
                } else {
                    throw $e;
                }
            }
        }

        // Mark invitation as accepted
        $invitation->setStatus('accepted');
        $invitation->setAcceptedAt(new \DateTime());
        $this->entityManager->flush();
    }


    /**
     * Decline invitation
     */
    public function declineInvitation(InvitationEntity $invitation): void
    {
        $this->crudManager->update($invitation, [
            'status' => 'declined'
        ]);
    }

    /**
     * Resend invitation
     */
    public function resendInvitation(InvitationEntity $invitation): void
    {
        if ($invitation->getStatus() !== 'pending') {
            throw new InvitationsException('Can only resend pending invitations.');
        }

        $this->sendInvitationEmail($invitation);
    }

    /**
     * Get one invitation
     */
    public function getOne(int $id): ?InvitationEntity
    {
        $repository = $this->entityManager->getRepository(InvitationEntity::class);
        
        return $repository->findOneBy([
            'id' => $id,
            'deleted' => false
        ]);
    }

    /**
     * Delete invitation
     */
    public function delete(InvitationEntity $invitation): void
    {
        $this->crudManager->delete($invitation);
    }
}