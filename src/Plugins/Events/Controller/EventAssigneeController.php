<?php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Events\Service\EventAssigneeService;
use App\Plugins\Events\Exception\EventsException;
use App\Plugins\Organizations\Service\UserOrganizationService;

#[Route('/api')]
class EventAssigneeController extends AbstractController
{
    private ResponseService $responseService;
    private EventService $eventService;
    private EventAssigneeService $assigneeService;
    private UserOrganizationService $userOrganizationService;

    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        EventAssigneeService $assigneeService,
        UserOrganizationService $userOrganizationService
    ) {
        $this->responseService = $responseService;
        $this->eventService = $eventService;
        $this->assigneeService = $assigneeService;
        $this->userOrganizationService = $userOrganizationService;
    }

    #[Route('/events/{id}/assignees', name: 'event_assignees_get#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getEventAssignees(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $organization_id = $request->query->get('organization_id');
        
        try {
            // Check if organization_id is provided
            if (!$organization_id) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }
            
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Get assignees for this event
            $assignees = $this->assigneeService->getAssigneesByEvent($event);
            
            // Convert to array format
            $result = array_map(function($assignee) {
                return $assignee->toArray();
            }, $assignees);
            
            return $this->responseService->json(true, 'Event assignees retrieved successfully.', $result);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }


    #[Route('/events/{id}/assignees', name: 'event_assignees_post#', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setEventAssignees(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        $organization_id = $request->query->get('organization_id');
        
        try {
            // Validation checks
            if (!$organization_id) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }
            
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            if (!$event = $this->eventService->getEventByIdAndOrganization($id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Verify permission
            $currentUserAssignee = $this->assigneeService->getAssigneeByEventAndUser($event, $user);
            if (!$currentUserAssignee || !in_array($currentUserAssignee->getRole(), ['creator', 'admin'])) {
                return $this->responseService->json(false, 'You do not have permission to manage event assignees.');
            }
            
            // Extract event settings if provided
            $assigneesData = $data['assignees'];


            // Validate assignees data
            if (!is_array($assigneesData)) {
                return $this->responseService->json(false, 'Invalid data format. Expected array of assignees.');
            }
            
            // Update assignees
            $assigneeResult = $this->assigneeService->updateEventAssignees($event, $assigneesData);
            
            // Update event settings if provided
            
            $eventUpdateData = [];
            
            if (isset($data['availabilityType'])) {
                if (in_array($data['availabilityType'], [
                    'one_host_available', 
                    'all_hosts_available'
                ])) {
                    $eventUpdateData['availabilityType'] = $data['availabilityType'];
                }
            }
            
            if (isset($data['acceptanceRequired'])) {
                $eventUpdateData['acceptanceRequired'] = (bool)$data['acceptanceRequired'];
            }
            

            if (!empty($eventUpdateData)) {
                $eventUpdateData['organization_id'] = (int)$organization_id;
                $this->eventService->update($event, $eventUpdateData);
            }
            

            
            // Prepare response
            $result = [
                'assignees' => $assigneeResult,
                'event' => [
                    'id' => $event->getId(),
                    'availabilityType' => $event->getAvailabilityType(),
                    'acceptanceRequired' => $event->isAcceptanceRequired()
                ]
            ];
            
            return $this->responseService->json(true, 'Event assignees updated successfully.', $result);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
    

    #[Route('/events/{event_id}/assignees/{assignee_id}', name: 'event_assignee_remove#', methods: ['DELETE'], requirements: ['event_id' => '\d+', 'assignee_id' => '\d+'])]
    public function removeEventAssignee(int $event_id, int $assignee_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $organization_id = $request->query->get('organization_id');
        
        try {
            // Check if organization_id is provided
            if (!$organization_id) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }
            
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Check if current user has permission to manage assignees
            $currentUserAssignee = $this->assigneeService->getAssigneeByEventAndUser($event, $user);
            if (!$currentUserAssignee || !in_array($currentUserAssignee->getRole(), ['creator', 'admin'])) {
                return $this->responseService->json(false, 'You do not have permission to remove assignees.');
            }
            
            // Get the assignee to remove
            $assignee = $this->assigneeService->getOne($assignee_id, ['event' => $event]);
            
            if (!$assignee) {
                return $this->responseService->json(false, 'Assignee was not found.');
            }
            
            // Don't allow removing creator
            if ($assignee->getRole() === 'creator') {
                return $this->responseService->json(false, 'The creator assignee cannot be removed.');
            }
            
            // Remove the assignee
            $this->assigneeService->delete($assignee);
            
            return $this->responseService->json(true, 'Assignee removed successfully.');
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
    

    #[Route('/events/{id}/assignees', name: 'event_assignees_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateEventAssignees(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        $organization_id = $request->query->get('organization_id');
        
        try {
            // Check if organization_id is provided
            if (!$organization_id) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }
            
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Verify the current user has permission to manage assignees
            $currentUserAssignee = $this->assigneeService->getAssigneeByEventAndUser($event, $user);
            if (!$currentUserAssignee || !in_array($currentUserAssignee->getRole(), ['creator', 'admin'])) {
                return $this->responseService->json(false, 'You do not have permission to manage event assignees.');
            }
            
            // Validate input data
            if (empty($data['assignees']) || !is_array($data['assignees'])) {
                return $this->responseService->json(false, 'Assignees data is required and must be an array.');
            }
            
            // Update assignees
            $result = $this->assigneeService->updateEventAssignees($event, $data['assignees'], $user);
            
            return $this->responseService->json(true, 'Event assignees updated successfully.', $result);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
}