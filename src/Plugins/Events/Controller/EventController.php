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
use App\Plugins\Organizations\Service\OrganizationService;
use App\Plugins\Teams\Service\TeamService;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Service\BookingReminderService;


use DateTime;
use DateTimeZone;
use DateInterval;


#[Route('/api')]
class EventController extends AbstractController
{
    private ResponseService $responseService;
    private EventService $eventService;
    private EventScheduleService $scheduleService;
    private UserOrganizationService $userOrganizationService;
    private TeamService $teamService;
    private OrganizationService $organizationService;
    private BookingReminderService $reminderService;
 
    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        EventScheduleService $scheduleService,
        UserOrganizationService $userOrganizationService,
        TeamService $teamService,
        OrganizationService $organizationService,
        BookingReminderService $reminderService
    ) {
        $this->responseService = $responseService;
        $this->eventService = $eventService;
        $this->scheduleService = $scheduleService;
        $this->userOrganizationService = $userOrganizationService;
        $this->teamService = $teamService;
        $this->organizationService = $organizationService; 
        $this->reminderService = $reminderService;
    }

    #[Route('/events', name: 'events_get_many#', methods: ['GET'])]
    public function getEvents(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $filters = $request->attributes->get('filters');
        $page = $request->attributes->get('page');
        $limit = $request->attributes->get('limit');
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
            
            // Get events within this organization
            $events = $this->eventService->getMany($filters, $page, $limit, [
                'organization' => $organization->entity
            ]);
            
            $result = [];
            foreach ($events as $event) {
                $eventData = $event->toArray();
                
                // Add schedule to response
                $eventData['schedule'] = $event->getSchedule();
                

                // Add assignees
                $assignees = $this->eventService->getAssignees($event);
                $eventData['assignees'] = array_map(function($assignee) {
                    return $assignee->toArray();
                }, $assignees);

                $eventData['location'] = $event->getLocation();
                
                $result[] = $eventData;
            }
            
            return $this->responseService->json(true, 'Events retrieved successfully.', $result);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{id}', name: 'events_get_one#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getEventById(int $id, Request $request): JsonResponse
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
            
            $eventData = $event->toArray();
            
            // Add schedule to response
            $eventData['schedule'] = $event->getSchedule();

            // Add location to response
            $eventData['location'] = $event->getLocation();
            
            // Add form fields
            $formFields = $this->eventService->getFormFields($event);
            $eventData['form_fields'] = array_map(function($field) {
                return $field->toArray();
            }, $formFields);
            

            
            // Add assignees
            $assignees = $this->eventService->getAssignees($event);
            $eventData['assignees'] = array_map(function($assignee) {
                return $assignee->toArray();
            }, $assignees);
            
            return $this->responseService->json(true, 'Event retrieved successfully.', $eventData);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events', name: 'events_create#', methods: ['POST'])]
    public function createEvent(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        $organization_id = $data['organization_id'] ?? $request->query->get('organization_id');
        
        try {
            if ($request->query->has('organization_id')) {
                $data['organization_id'] = (int)$request->query->get('organization_id');
            }
            // Check if organization_id is provided
            if (!$organization_id) {
                return $this->responseService->json(false, 'Organization ID is required.');
            }
            
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
      
            // Handle the duration format conversion
            if (isset($data['duration']) && is_numeric($data['duration'])) {
                // Convert single integer duration to array format
                $data['duration'] = [
                    [
                        'title' => 'Standard Meeting',
                        'description' => '',
                        'duration' => (int)$data['duration']
                    ]
                ];
            } elseif (!isset($data['duration']) || !is_array($data['duration'])) {
                // Set default duration if not provided
                $data['duration'] = [
                    [
                        'title' => 'Standard Meeting',
                        'description' => '',
                        'duration' => 30
                    ]
                ];
            }
            
            // Check team if provided
            if (!empty($data['team_id'])) {
                $team = $this->teamService->getTeamByIdAndOrganization($data['team_id'], $organization->entity);
                if (!$team) {
                    return $this->responseService->json(false, 'Team was not found or does not belong to this organization.');
                }
            }
            
            // Create event with organization and creator set
            $event = $this->eventService->create($data, function($event) use ($organization, $user, $data) {
                $event->setOrganization($organization->entity);
                $event->setCreatedBy($user);
                
                // Set team if provided
                if (!empty($data['team_id'])) {
                    $team = $this->teamService->getTeamByIdAndOrganization($data['team_id'], $organization->entity);
                    if ($team) {
                        $event->setTeam($team);
                    }
                }
            });
            
            // Prepare response
            $eventData = $event->toArray();
            
            // Add schedule to response
            $eventData['schedule'] = $event->getSchedule();
            
            // Add form fields
            $formFields = $this->eventService->getFormFields($event);
            $eventData['form_fields'] = array_map(function($field) {
                return $field->toArray();
            }, $formFields);


            // availability and acceptance (fixed hosts)
            if (!isset($data['availability_type'])) {
                $data['availability_type'] = 'one_host_available';
            }
            
            if (!isset($data['acceptance_required'])) {
                $data['acceptance_required'] = false;
            }
            
            
            // Add assignees
            $assignees = $this->eventService->getAssignees($event);
            $eventData['assignees'] = array_map(function($assignee) {
                return $assignee->toArray();
            }, $assignees);
            
            return $this->responseService->json(true, 'Event created successfully.', $eventData, 201);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{id}', name: 'events_update#', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateEvent(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        $organization_id = $data['organization_id'] ?? $request->query->get('organization_id');
        
        try {
            // Check if organization_id is provided
            if ($request->query->has('organization_id')) {
                $data['organization_id'] = (int)$request->query->get('organization_id');
            }
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
            
            // Handle the duration format conversion
            if (isset($data['duration']) && is_numeric($data['duration'])) {
                // Convert single integer duration to array format
                $data['duration'] = [
                    [
                        'title' => 'Standard Meeting',
                        'description' => '',
                        'duration' => (int)$data['duration']
                    ]
                ];
            }
            
            // Check team if provided
            if (!empty($data['team_id'])) {
                $team = $this->teamService->getTeamByIdAndOrganization($data['team_id'], $organization->entity);
                if (!$team) {
                    return $this->responseService->json(false, 'Team was not found or does not belong to this organization.');
                }
                $data['team'] = $team; // Set the actual team object
            }
            
            // Update the event
            $this->eventService->update($event, $data);
            
            // Prepare response
            $eventData = $event->toArray();
            
            // Add schedule to response
            $eventData['schedule'] = $event->getSchedule();
            
            // Add form fields
            $formFields = $this->eventService->getFormFields($event);
            $eventData['form_fields'] = array_map(function($field) {
                return $field->toArray();
            }, $formFields);


            // availability and acceptance (fixed hosts)
            if (isset($data['availability_type']) && !in_array($data['availability_type'], ['one_host_available', 'all_hosts_available'])) {
                return $this->responseService->json(false, 'Invalid availability type.', null, 400);
            }
            
            if (isset($data['acceptance_required']) && !is_bool($data['acceptance_required'])) {
                $data['acceptance_required'] = (bool)$data['acceptance_required'];
            }
            
            
            // Add assignees
            $assignees = $this->eventService->getAssignees($event);
            $eventData['assignees'] = array_map(function($assignee) {
                return $assignee->toArray();
            }, $assignees);
            
            return $this->responseService->json(true, 'Event updated successfully.', $eventData);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }

    #[Route('/events/{id}', name: 'events_delete#', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteEvent(int $id, Request $request): JsonResponse
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
            
            $this->eventService->delete($event);
            return $this->responseService->json(true, 'Event deleted successfully.');
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }   



    #[Route('/events/{id}/people', name: 'event_people#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getEventPeople(int $id, Request $request): JsonResponse
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
            
            // Get all eligible people for this event
            $people = $this->eventService->getEligiblePeople($event);
            
            return $this->responseService->json(true, 'Event eligible people retrieved successfully.', $people);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }
    


    /* PUBLIC ROUTE WITHOUT USER AUTHENTICATION */
    /* PUBLIC ROUTE WITHOUT USER AUTHENTICATION */
    /* PUBLIC ROUTE WITHOUT USER AUTHENTICATION */
    /* PUBLIC ROUTE WITHOUT USER AUTHENTICATION */
    #[Route('/public/organizations/{org_slug}/events/{event_slug}', name: 'public_event_info', methods: ['GET'])]
    public function getPublicEventInfo(string $org_slug, string $event_slug, Request $request): JsonResponse
    {
        try {
            // Get organization by slug
            $organization = $this->organizationService->getBySlug($org_slug);
            
            if (!$organization) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }
            
            // Get event by slug and organization
            $event = $this->eventService->getEventBySlug($event_slug, null, $organization);
            
            if (!$event || $event->isDeleted()) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }
            
            $eventData = $event->toArray();
            
            // Add schedule to response
            $eventData['schedule'] = $event->getSchedule();
            
            // Add location information with enhanced structure
            $eventData['location'] = $this->formatLocationForPublicApi($event->getLocation());
            
            // Add available location options if multiple locations are configured
            $eventData['available_locations'] = $this->getAvailableLocations($event);
            
            // Add form fields
            $formFields = $this->eventService->getFormFields($event);
            $eventData['form_fields'] = array_map(function($field) {
                return $field->toArray();
            }, $formFields);
            
            // Add duration options with proper formatting
            $eventData['duration_options'] = $this->formatDurationOptions($event->getDuration());
            
            // Add organization details
            $eventData['organization'] = [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'slug' => $organization->getSlug()
            ];
            
            // Remove sensitive data but keep organization_id
            unset($eventData['created_by']);
            
            // Add metadata for frontend
            $eventData['metadata'] = [
                'requires_form' => !empty($formFields),
                'has_multiple_locations' => count($eventData['available_locations']) > 1,
                'has_multiple_durations' => count($eventData['duration_options']) > 1,
            ];
            
            return $this->responseService->json(true, 'retrieve', $eventData);
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e, null, 500);
        }
    }


    

    

    #[Route('/public/organizations/{org_slug}/events/{event_slug}/available-slots', name: 'public_event_available_slots', methods: ['GET'])]
    public function getPublicAvailableSlots(string $org_slug, string $event_slug, Request $request): JsonResponse
    {
        $date = $request->query->get('date');
        $requestedDuration = $request->query->get('duration');
        $timezone = $request->query->get('timezone', 'UTC');
        
        if (!$date) {
            return $this->responseService->json(false, 'Date parameter is required.', null, 400);
        }
        
        try {
            // Get organization by slug
            $organization = $this->organizationService->getBySlug($org_slug);
            
            if (!$organization) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }

            // Get event by slug and organization
            $event = $this->eventService->getEventBySlug($event_slug, null, $organization);

            if (!$event || $event->isDeleted()) {
                return $this->responseService->json(false, 'not-found', null, 404);
            }
            
            // Get buffer time from event (stored in minutes)
            $bufferMinutes = $event->getBufferTime();
            
            // Get durations from event
            $durations = $event->getDuration();
            $durationMinutes = 30; // Default duration
            
            // If duration is specified in URL, check if it's valid
            if ($requestedDuration) {
                $requestedDuration = (int)$requestedDuration;
                $durationFound = false;
                
                // Check if requested duration exists in event durations
                foreach ($durations as $option) {
                    if (isset($option['duration']) && (int)$option['duration'] === $requestedDuration) {
                        $durationMinutes = $requestedDuration;
                        $durationFound = true;
                        break;
                    }
                }
                
                // If not found, use default
                if (!$durationFound && !empty($durations) && isset($durations[0]['duration'])) {
                    $durationMinutes = (int)$durations[0]['duration'];
                }
            }
            
            // Create DateTime object with the client's timezone
            try {
                // Validate timezone
                new \DateTimeZone($timezone);
            } catch (\Exception $e) {
                // Default to UTC if invalid timezone
                $timezone = 'UTC';
            }
            
            // Get available slots for the specified date with timezone support and buffer time
            $dateObj = new \DateTime($date, new \DateTimeZone($timezone));
            $slots = $this->scheduleService->getAvailableTimeSlots(
                $event, 
                $dateObj, 
                $durationMinutes, 
                $timezone,
                $bufferMinutes // Pass buffer time from event (in minutes)
            );
            
            return $this->responseService->json(true, 'retrieve', [
                'slots' => $slots,
                'event' => [
                    'id' => $event->getId(),
                    'name' => $event->getName(),
                    'buffer_time' => $bufferMinutes
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }





    /**
     * Format location data for public API consumption
     */
    private function formatLocationForPublicApi($location): ?array
    {
        if (!$location) {
            return null;
        }
        
        // Handle single location object
        if (isset($location['type'])) {
            return $this->formatSingleLocation($location);
        }
        
        // Handle array of locations
        if (is_array($location) && isset($location[0])) {
            return array_map([$this, 'formatSingleLocation'], $location);
        }
        
        return null;
    }

    /**
     * Format a single location entry
     */
    private function formatSingleLocation(array $location): array
    {
        $formatted = [
            'type' => $location['type'] ?? 'unknown',
            'display_name' => $this->getLocationDisplayName($location),
            'requires_input' => false,
            'input_label' => null,
            'icon' => $this->getLocationIcon($location['type'] ?? 'unknown')
        ];
        
        // Add type-specific fields
        switch ($location['type']) {
            case 'phone':
                $formatted['requires_input'] = true;
                $formatted['input_label'] = 'Phone Number';
                $formatted['input_type'] = 'tel';
                break;
                
            case 'custom':
                $formatted['requires_input'] = true;
                $formatted['input_label'] = $location['label'] ?? 'Location Details';
                $formatted['input_type'] = 'text';
                break;
                
            case 'google_meet':
            case 'zoom':
            case 'teams':
                $formatted['auto_generated'] = true;
                $formatted['provider'] = $location['type'];
                break;
                
            case 'in_person':
                $formatted['address'] = $location['address'] ?? null;
                $formatted['instructions'] = $location['instructions'] ?? null;
                break;
        }
        
        return $formatted;
    }


    /**
     * Get available locations for an event
     */
    private function getAvailableLocations(EventEntity $event): array
    {
        $locations = $event->getLocation();
        
        if (!$locations) {
            return [];
        }
        
        // If it's a single location, wrap it in an array
        if (isset($locations['type'])) {
            return [$this->formatSingleLocation($locations)];
        }
        
        // If it's already an array of locations
        if (is_array($locations)) {
            $formatted = [];
            foreach ($locations as $location) {
                if (is_array($location) && isset($location['type'])) {
                    $formatted[] = $this->formatSingleLocation($location);
                }
            }
            return $formatted;
        }
        
        return [];
    }

    /**
     * Get display name for location type
     */
    private function getLocationDisplayName(array $location): string
    {
        $type = $location['type'] ?? 'unknown';
        
        $displayNames = [
            'google_meet' => 'Google Meet',
            'zoom' => 'Zoom Meeting',
            'teams' => 'Microsoft Teams',
            'phone' => 'Phone Call',
            'in_person' => 'In-Person Meeting',
            'custom' => $location['label'] ?? 'Custom Location'
        ];
        
        return $displayNames[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * Get icon for location type
     */
    private function getLocationIcon(string $type): string
    {
        $icons = [
            'google_meet' => 'video',
            'zoom' => 'video',
            'teams' => 'video',
            'phone' => 'phone',
            'in_person' => 'map-pin',
            'custom' => 'location'
        ];
        
        return $icons[$type] ?? 'location';
    }

    /**
     * Format duration options for better frontend consumption
     */
    private function formatDurationOptions(?array $durations): array
    {
        if (!$durations || !is_array($durations)) {
            return [[
                'duration' => 30,
                'title' => '30 minutes',
                'description' => '',
                'is_default' => true
            ]];
        }
        
        $formatted = [];
        foreach ($durations as $index => $duration) {
            $formatted[] = [
                'duration' => (int)($duration['duration'] ?? 30),
                'title' => $duration['title'] ?? ($duration['duration'] . ' minutes'),
                'description' => $duration['description'] ?? '',
                'is_default' => $index === 0
            ];
        }
        
        return $formatted;
    }



    #[Route('/public/organizations/{org_slug}/events/{event_slug}/initial-load', name: 'public_event_initial_load', methods: ['GET'])]
    public function getPublicEventInitialLoad(string $org_slug, string $event_slug, Request $request): JsonResponse
    {
        try {
            // Get timezone and other parameters
            $timezone = $request->query->get('timezone', 'UTC');
            $requestedDuration = $request->query->get('duration');
            
            // First, get the organization by slug
            $organization = $this->organizationService->getBySlug($org_slug);
            if (!$organization) {
                return $this->responseService->json(false, 'Organization not found.', null, 404);
            }
            
            // Get event by slug using the correct method signature
            $event = $this->eventService->getEventBySlug($event_slug, null, $organization);
            if (!$event || $event->isDeleted()) {
                return $this->responseService->json(false, 'Event not found.', null, 404);
            }
            
            // Get buffer time from event (stored in minutes)
            $bufferMinutes = $event->getBufferTime();
            
            // Build event data (same as getPublicEventInfo)
            $eventData = $event->toArray();
            $eventData['schedule'] = $event->getSchedule();
            $eventData['location'] = $this->formatLocationForPublicApi($event->getLocation());
            $eventData['available_locations'] = $this->getAvailableLocations($event);
            
            $formFields = $this->eventService->getFormFields($event);
            $eventData['form_fields'] = array_map(function($field) {
                return $field->toArray();
            }, $formFields);
            
            $eventData['duration_options'] = $this->formatDurationOptions($event->getDuration());
            $eventData['organization'] = [
                'id' => $organization->getId(),
                'name' => $organization->getName(),
                'slug' => $organization->getSlug()
            ];
            
            // Add buffer time to event data
            $eventData['buffer_time'] = $bufferMinutes;
            
            // Remove sensitive data
            unset($eventData['created_by']);
            
            $eventData['metadata'] = [
                'requires_form' => !empty($formFields),
                'has_multiple_locations' => count($eventData['available_locations']) > 1,
                'has_multiple_durations' => count($eventData['duration_options']) > 1,
            ];
            
            // Now get available slots for today (or next available day)
            $today = new \DateTime('now', new \DateTimeZone($timezone));
            $duration = $requestedDuration ?: ($event->getDuration()[0]['duration'] ?? 30);
            
            // Find first available day (check up to 30 days)
            $maxDaysToCheck = 30;
            $availableSlots = [];
            $selectedDate = null;
            
            for ($i = 0; $i < $maxDaysToCheck; $i++) {
                $checkDate = clone $today;
                $checkDate->add(new \DateInterval('P' . $i . 'D'));
                
                $slots = $this->scheduleService->getAvailableTimeSlots(
                    $event,
                    $checkDate,
                    $duration,
                    $timezone,
                    $bufferMinutes // Pass buffer time from event (in minutes)
                );
                
                if (!empty($slots)) {
                    $selectedDate = $checkDate->format('Y-m-d');
                    $availableSlots = $slots;
                    break;
                }
            }
            
            // Return combined response
            return $this->responseService->json(true, 'retrieve', [
                'event' => $eventData,
                'initial_slots' => [
                    'date' => $selectedDate,
                    'duration' => $duration,
                    'timezone' => $timezone,
                    'buffer_time' => $bufferMinutes,
                    'slots' => $availableSlots
                ]
            ]);
            
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

}