<?php
// src/Plugins/Events/Controller/EventBatchController.php
//
// FULL PATH: src/Plugins/Events/Controller/EventBatchController.php
//
// This controller provides batch endpoints to fetch multiple events efficiently
// instead of making N+1 API calls.

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Service\CrudManager;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Organizations\Service\UserOrganizationService;

#[Route('/api')]
class EventBatchController extends AbstractController
{
    private ResponseService $responseService;
    private EventService $eventService;
    private UserOrganizationService $userOrganizationService;
    private CrudManager $crudManager;

    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        UserOrganizationService $userOrganizationService,
        CrudManager $crudManager
    ) {
        $this->responseService = $responseService;
        $this->eventService = $eventService;
        $this->userOrganizationService = $userOrganizationService;
        $this->crudManager = $crudManager;
    }

    /**
     * Batch fetch multiple events by IDs
     * 
     * POST /api/events/batch
     * Body: { "events": [{ "id": 1, "organization_id": 1 }, { "id": 2, "organization_id": 1 }] }
     * 
     * This replaces N individual API calls with a single batch request.
     */
    #[Route('/events/batch', name: 'events_batch_get#', methods: ['POST'])]
    public function getEventsBatch(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = json_decode($request->getContent(), true);

        try {
            if (empty($data['events']) || !is_array($data['events'])) {
                return $this->responseService->json(false, 'Events array is required.');
            }

            // Limit batch size to prevent abuse
            $maxBatchSize = 100;
            if (count($data['events']) > $maxBatchSize) {
                return $this->responseService->json(false, "Batch size exceeds maximum of {$maxBatchSize} events.");
            }

            // Group events by organization for efficient access checking
            $eventsByOrg = [];
            foreach ($data['events'] as $eventRequest) {
                if (empty($eventRequest['id']) || empty($eventRequest['organization_id'])) {
                    continue;
                }
                $orgId = (int) $eventRequest['organization_id'];
                if (!isset($eventsByOrg[$orgId])) {
                    $eventsByOrg[$orgId] = [];
                }
                $eventsByOrg[$orgId][] = (int) $eventRequest['id'];
            }

            $result = [];
            $errors = [];

            // Process each organization's events
            foreach ($eventsByOrg as $orgId => $eventIds) {
                // Check if user has access to this organization
                $organization = $this->userOrganizationService->getOrganizationByUser($orgId, $user);
                if (!$organization) {
                    $errors[] = "No access to organization {$orgId}";
                    continue;
                }

                // Fetch all events for this organization in one query using CrudManager
                $events = $this->crudManager->findMany(
                    EventEntity::class,
                    [],
                    1,
                    count($eventIds),
                    [
                        'organization' => $organization->entity,
                        'deleted' => false
                    ],
                    function ($queryBuilder) use ($eventIds) {
                        $queryBuilder->andWhere('t1.id IN (:eventIds)')
                            ->setParameter('eventIds', $eventIds);
                    }
                );

                // Process each event with full details
                foreach ($events as $event) {
                    $eventData = $event->toArray();
                    
                    // Add schedule
                    $eventData['schedule'] = $event->getSchedule();
                    
                    // Add location
                    $eventData['location'] = $event->getLocation();
                    
                    // Add assignees
                    $assignees = $this->eventService->getAssignees($event);
                    $eventData['assignees'] = array_map(function($assignee) {
                        return $assignee->toArray();
                    }, $assignees);
                    
                    // Add form fields
                    $formFields = $this->eventService->getFormFields($event);
                    $eventData['form_fields'] = array_map(function($field) {
                        return $field->toArray();
                    }, $formFields);

                    // Add team info if exists
                    if ($event->getTeam()) {
                        $eventData['team_id'] = $event->getTeam()->getId();
                        $eventData['team_name'] = $event->getTeam()->getName();
                    }

                    $result[] = $eventData;
                }
            }

            return $this->responseService->json(true, 'Events retrieved successfully.', [
                'events' => $result,
                'errors' => $errors,
                'total' => count($result)
            ]);

        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to fetch events: ' . $e->getMessage(), null, 500);
        }
    }
}