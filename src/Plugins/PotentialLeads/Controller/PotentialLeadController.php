<?php

namespace App\Plugins\PotentialLeads\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\ResponseService;
use App\Plugins\PotentialLeads\Service\PotentialLeadService;
use App\Plugins\PotentialLeads\Exception\PotentialLeadsException;
use App\Plugins\Events\Repository\EventRepository;
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Organizations\Service\UserOrganizationService;
use App\Plugins\Events\Service\EventService;

class PotentialLeadController extends AbstractController
{
    private ResponseService $responseService;
    private PotentialLeadService $potentialLeadService;
    private EntityManagerInterface $entityManager;
    private EventRepository $eventRepository;
    private OrganizationService $organizationService;
    private UserOrganizationService $userOrganizationService;
    private EventService $eventService;

    public function __construct(
        ResponseService $responseService,
        PotentialLeadService $potentialLeadService,
        EntityManagerInterface $entityManager,
        EventRepository $eventRepository,
        OrganizationService $organizationService,
        UserOrganizationService $userOrganizationService,
        EventService $eventService
    ) {
        $this->responseService = $responseService;
        $this->potentialLeadService = $potentialLeadService;
        $this->entityManager = $entityManager;
        $this->eventRepository = $eventRepository;
        $this->organizationService = $organizationService;
        $this->userOrganizationService = $userOrganizationService;
        $this->eventService = $eventService;
    }

    #[Route('/api/public/events/{eventSlug}/potential-lead', name: 'add_potential_lead', methods: ['POST'])]
    public function addPotentialLead(string $eventSlug, Request $request): JsonResponse
    {
        try {
            // Get event by slug
            $event = $this->eventRepository->findOneBy(['slug' => $eventSlug]);
            if (!$event) {
                return $this->responseService->json(false, 'Event not found', [], 404);
            }

            // Get request data
            $data = json_decode($request->getContent(), true);
            
            // Validate required fields
            if (empty($data['email'])) {
                return $this->responseService->json(false, 'Email is required', [], 400);
            }

            // Add timezone if not provided
            if (empty($data['timezone'])) {
                $data['timezone'] = $request->headers->get('X-Timezone', 'UTC');
            }

            // Add captured timestamp
            $data['captured_at'] = (new \DateTime())->format('Y-m-d H:i:s');

            // Add potential lead
            $potentialLead = $this->potentialLeadService->addFromEvent($event, $data);

            if ($potentialLead) {
                return $this->responseService->json(
                    true, 
                    'Potential lead captured successfully',
                    ['id' => $potentialLead->getId()]
                );
            } else {
                // Already exists as contact or lead
                return $this->responseService->json(
                    true, 
                    'Email already registered',
                    []
                );
            }

        } catch (PotentialLeadsException $e) {
            return $this->responseService->json(false, $e->getMessage(), [], 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', [], 500);
        }
    }

    #[Route('/api/user/potential-leads/my-leads', name: 'my_potential_leads#', methods: ['GET'])]
    public function myPotentialLeads(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get query parameters
            $page = (int) $request->query->get('page', 1);
            $limit = (int) $request->query->get('limit', 50);
            $filters = [
                'search' => $request->query->get('search', '')
            ];

            // Get host potential leads for current user
            $result = $this->potentialLeadService->getHostPotentialLeads(
                $user,
                null, // No organization filter for "My Leads"
                $filters,
                $page,
                $limit
            );

            return $this->responseService->json(true, 'My potential leads retrieved successfully', $result);

        } catch (PotentialLeadsException $e) {
            return $this->responseService->json(false, $e->getMessage(), [], 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to fetch potential leads', [], 500);
        }
    }

    #[Route('/api/user/organizations/{organizationId}/potential-leads', name: 'organization_potential_leads#', methods: ['GET'])]
    public function organizationPotentialLeads(int $organizationId, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organizationId);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', [], 404);
            }

            // Check permissions - FIXED LINE
            $userOrganization = $this->userOrganizationService->isUserInOrganization($user, $organization);
            if (!$userOrganization) {
                return $this->responseService->json(false, 'Unauthorized', [], 403);
            }

            // Get query parameters
            $page = (int) $request->query->get('page', 1);
            $limit = (int) $request->query->get('limit', 50);
            $filters = [
                'search' => $request->query->get('search', '')
            ];

            // Get organization potential leads
            $result = $this->potentialLeadService->getOrganizationPotentialLeads(
                $organization,
                $filters,
                $page,
                $limit
            );

            return $this->responseService->json(true, 'Organization potential leads retrieved successfully', $result);

        } catch (PotentialLeadsException $e) {
            return $this->responseService->json(false, $e->getMessage(), [], 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to fetch potential leads', [], 500);
        }
    }

    #[Route('/api/user/potential-leads/{leadId}', name: 'delete_my_potential_lead#', methods: ['DELETE'])]
    public function deleteMyPotentialLead(int $leadId, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            $this->potentialLeadService->deleteHostPotentialLead($leadId, $user);
            return $this->responseService->json(true, 'Potential lead removed successfully');

        } catch (PotentialLeadsException $e) {
            return $this->responseService->json(false, $e->getMessage(), [], 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to delete potential lead', [], 500);
        }
    }

    #[Route('/api/user/organizations/{organizationId}/potential-leads/{leadId}', name: 'delete_organization_potential_lead#', methods: ['DELETE'])]
    public function deleteOrganizationPotentialLead(int $organizationId, int $leadId, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organizationId);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', [], 404);
            }

            // Check permissions (must be admin) - FIXED LINE HERE
            $userOrganization = $this->userOrganizationService->isUserInOrganization($user, $organization);
            if (!$userOrganization || !in_array($userOrganization->getRole(), ['admin', 'owner', 'creator'])) {
                return $this->responseService->json(false, 'Unauthorized', [], 403);
            }

            $this->potentialLeadService->deleteOrganizationPotentialLead($leadId, $organization);
            return $this->responseService->json(true, 'Potential lead removed successfully');

        } catch (PotentialLeadsException $e) {
            return $this->responseService->json(false, $e->getMessage(), [], 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to delete potential lead', [], 500);
        }
    }

    #[Route('/api/user/potential-leads/export', name: 'export_my_potential_leads#', methods: ['GET'])]
    public function exportMyPotentialLeads(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $user = $request->attributes->get('user');

        try {
            $filters = [
                'search' => $request->query->get('search', '')
            ];

            return $this->potentialLeadService->exportHostPotentialLeads($user, null, $filters);

        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to export potential leads', [], 500);
        }
    }

    #[Route('/api/user/organizations/{organizationId}/potential-leads/export', name: 'export_organization_potential_leads#', methods: ['GET'])]
    public function exportOrganizationPotentialLeads(int $organizationId, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $user = $request->attributes->get('user');

        try {
            // Get organization
            $organization = $this->organizationService->getOne($organizationId);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', [], 404);
            }

            // Check permissions - FIXED LINE
            $userOrganization = $this->userOrganizationService->isUserInOrganization($user, $organization);
            if (!$userOrganization) {
                return $this->responseService->json(false, 'Unauthorized', [], 403);
            }

            $filters = [
                'search' => $request->query->get('search', '')
            ];

            return $this->potentialLeadService->exportOrganizationPotentialLeads($organization, $filters);

        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to export potential leads', [], 500);
        }
    }


    #[Route('/api/organizations/{organization_id}/events/{event_id}/potential-lead', name: 'add_potential_lead_by_ids', methods: ['POST'], requirements: ['organization_id' => '\d+', 'event_id' => '\d+'])]
    public function addPotentialLeadByIds(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        try {
            // Get organization
            $organization = $this->organizationService->getOne($organization_id);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found', [], 404);
            }

            // Get event and verify it belongs to the organization
            $event = $this->eventService->getOne($event_id);
            if (!$event || $event->getOrganization()->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Event not found', [], 404);
            }

            // Get request data
            $data = json_decode($request->getContent(), true);
            
            // Validate required fields
            if (empty($data['email'])) {
                return $this->responseService->json(false, 'Email is required', [], 400);
            }

            // Add timezone if not provided
            if (empty($data['timezone'])) {
                $data['timezone'] = $request->headers->get('X-Timezone', 'UTC');
            }

            // Add captured timestamp
            $data['captured_at'] = (new \DateTime())->format('Y-m-d H:i:s');

            // Add potential lead
            $potentialLead = $this->potentialLeadService->addFromEvent($event, $data);

            if ($potentialLead) {
                return $this->responseService->json(
                    true, 
                    'Potential lead captured successfully',
                    ['id' => $potentialLead->getId()]
                );
            } else {
                // Already exists as contact
                return $this->responseService->json(
                    true, 
                    'Email already registered',
                    []
                );
            }

        } catch (PotentialLeadsException $e) {
            return $this->responseService->json(false, $e->getMessage(), [], 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred', [], 500);
        }
    }


}