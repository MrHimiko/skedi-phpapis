<?php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Events\Service\EventScheduleService;
use App\Plugins\Events\Exception\EventsException;
use App\Plugins\Organizations\Service\UserOrganizationService;

#[Route('/api')]
class EventScheduleController extends AbstractController
{
    private ResponseService $responseService;
    private EventService $eventService;
    private EventScheduleService $scheduleService;
    private UserOrganizationService $userOrganizationService;

    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        EventScheduleService $scheduleService,
        UserOrganizationService $userOrganizationService
    ) {
        $this->responseService = $responseService;
        $this->eventService = $eventService;
        $this->scheduleService = $scheduleService;
        $this->userOrganizationService = $userOrganizationService;
    }

    #[Route('/events/{id}/schedule', name: 'event_schedule_get#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getSchedule(int $id, Request $request): JsonResponse
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
            
            // Get schedule for this event
            $schedule = $this->scheduleService->getScheduleForEvent($event);
            
            return $this->responseService->json(true, 'Schedule retrieved successfully.', $schedule);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{id}/schedule', name: 'event_schedule_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateSchedule(int $id, Request $request): JsonResponse
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
            
            // Validate schedule data format
            if (!is_array($data)) {
                return $this->responseService->json(false, 'Invalid schedule data format.');
            }
            
            // Update schedule
            $updatedSchedule = $this->scheduleService->updateEventSchedule($event, $data);
            
            return $this->responseService->json(true, 'Schedule updated successfully.', $updatedSchedule);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
    
    #[Route('/events/{id}/available-dates', name: 'event_available_dates#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getAvailableDates(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $startDate = $request->query->get('start_date');
        $endDate = $request->query->get('end_date');
        $organization_id = $request->query->get('organization_id');

        try {
            // Check if dates are provided
            if (!$startDate || !$endDate) {
                return $this->responseService->json(false, 'Start and end dates are required.');
            }
            
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
            
            // Convert date strings to DateTime objects
            $startDateObj = new \DateTime($startDate);
            $endDateObj = new \DateTime($endDate);
            
            // Check if end date is after start date
            if ($startDateObj > $endDateObj) {
                return $this->responseService->json(false, 'End date must be after start date.');
            }
            
            // Get available dates
            $availableDates = $this->scheduleService->getAvailableDates($event, $startDateObj, $endDateObj);
            
            return $this->responseService->json(true, 'Available dates retrieved successfully.', $availableDates);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
}