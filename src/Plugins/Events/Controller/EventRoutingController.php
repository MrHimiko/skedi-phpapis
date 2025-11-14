<?php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Events\Service\RoutingService;
use App\Plugins\Events\Service\EventAssigneeService;
use App\Plugins\Organizations\Service\UserOrganizationService;

#[Route('/api/organizations/{organization_id}', requirements: ['organization_id' => '\d+'])]
class EventRoutingController extends AbstractController
{
    private ResponseService $responseService;
    private EventService $eventService;
    private RoutingService $routingService;
    private EventAssigneeService $assigneeService;
    private UserOrganizationService $userOrganizationService;

    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        RoutingService $routingService,
        EventAssigneeService $assigneeService,
        UserOrganizationService $userOrganizationService
    ) {
        $this->responseService = $responseService;
        $this->eventService = $eventService;
        $this->routingService = $routingService;
        $this->assigneeService = $assigneeService;
        $this->userOrganizationService = $userOrganizationService;
    }

    #[Route('/events/{event_id}/routing/test', name: 'event_routing_test#', methods: ['POST'], requirements: ['event_id' => '\d+'])]
    public function testRouting(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = json_decode($request->getContent(), true);
        
        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Check if routing is enabled
            if (!$event->getRoutingEnabled()) {
                return $this->responseService->json(false, 'Routing is not enabled for this event.');
            }
            
            // Test routing with provided form data
            $testTime = new \DateTime($data['test_time'] ?? 'now');
            $formData = $data['form_data'] ?? [];
            
            $routedAssignee = $this->routingService->routeBooking(
                $event,
                $formData,
                $testTime
            );
            
            if ($routedAssignee) {
                $user = $routedAssignee->getUser();
                return $this->responseService->json(true, 'Routing test successful', [
                    'assigned_to' => $user->getId(),
                    'assigned_name' => $user->getName(),
                    'reason' => 'Based on routing instructions' // You can enhance this
                ]);
            } else {
                return $this->responseService->json(false, 'No assignee could be determined');
            }
            
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Test failed: ' . $e->getMessage(), null, 500);
        }
    }

    #[Route('/events/{event_id}/routing/stats', name: 'event_routing_stats#', methods: ['GET'], requirements: ['event_id' => '\d+'])]
    public function getRoutingStats(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Get routing statistics from the log table
            $sql = "SELECT 
                        assigned_to, 
                        COUNT(*) as total_routed,
                        routing_method,
                        DATE(created_at) as date
                    FROM event_routing_log
                    WHERE event_id = :event_id
                    GROUP BY assigned_to, routing_method, DATE(created_at)
                    ORDER BY date DESC
                    LIMIT 100";
            
            $stmt = $this->entityManager->getConnection()->prepare($sql);
            $stmt->execute(['event_id' => $event_id]);
            $stats = $stmt->fetchAllAssociative();
            
            return $this->responseService->json(true, 'Routing statistics retrieved', $stats);
            
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
}