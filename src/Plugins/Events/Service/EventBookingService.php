<?php

namespace App\Plugins\Events\Service;

use App\Service\CrudManager;
use App\Exception\CrudException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;

use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Entity\EventGuestEntity;
use App\Plugins\Events\Entity\ContactEntity;
use App\Plugins\Events\Exception\EventsException;
use App\Plugins\Integrations\Google\Calendar\Service\GoogleCalendarService;
//use App\Plugins\Integrations\Microsoft\Outlook\Service\OutlookCalendarService;
use App\Plugins\Integrations\Google\Meet\Service\GoogleMeetService;
use App\Plugins\Events\Service\BookingReminderService;
use App\Plugins\Contacts\Service\ContactService;
use App\Plugins\Workflows\Service\WorkflowExecutionService;
use App\Plugins\Account\Entity\UserEntity;
use DateTime;

class EventBookingService
{
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private ContactService $contactService;
    private EventScheduleService $scheduleService;
    private GoogleCalendarService $googleCalendarService;
    //private OutlookCalendarService $outlookCalendarService;
    private GoogleMeetService $googleMeetService;
    private BookingReminderService $reminderService;
    private WorkflowExecutionService $workflowExecutionService;
    private UserEntity $UserEntity;

    public function __construct(
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        ContactService $contactService,
        EventScheduleService $scheduleService,
        //OutlookCalendarService $outlookCalendarService,
        GoogleCalendarService $googleCalendarService,
        GoogleMeetService $googleMeetService,
        BookingReminderService $reminderService,
        WorkflowExecutionService $workflowExecutionService,
        UserEntity $UserEntity
    ) {
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->contactService = $contactService;
        $this->scheduleService = $scheduleService;
        $this->googleCalendarService = $googleCalendarService;
        //$this->outlookCalendarService = $outlookCalendarService;
        $this->googleMeetService = $googleMeetService;
        $this->reminderService = $reminderService;
        $this->workflowExecutionService = $workflowExecutionService;
        $this->UserEntity = $UserEntity;
    }

    public function getMany(array $filters, int $page, int $limit, array $criteria = []): array
    {
        try {
            return $this->crudManager->findMany(
                EventBookingEntity::class,
                $filters,
                $page,
                $limit,
                $criteria
            );
        } catch (CrudException $e) {
            throw new EventsException($e->getMessage());
        }
    }

    public function getOne(int $id, array $criteria = []): ?EventBookingEntity
    {
        return $this->crudManager->findOne(EventBookingEntity::class, $id, $criteria);
    }

    

    public function create(array $data): EventBookingEntity
    {
        try {
            // Validate event
            if (empty($data['event_id'])) {
                throw new EventsException('Event ID is required');
            }
            
            $event = $this->entityManager->getRepository(EventEntity::class)->find($data['event_id']);
            if (!$event) {
                throw new EventsException('Event not found');
            }
            
            // Parse times
            $startTime = new \DateTime($data['start_time']);
            $endTime = new \DateTime($data['end_time']);
            
           
            if (!$this->scheduleService->isTimeSlotAvailable($event, $startTime, $endTime)) {
                throw new EventsException('The selected time slot is not available for the event or its hosts');
            }
            
            // Create booking entity
            $booking = new EventBookingEntity();
            $booking->setEvent($event);
            $booking->setStartTime($startTime);
            $booking->setEndTime($endTime);
            $booking->setStatus($data['status'] ?? 'confirmed');
            $booking->setCancelled(false);
            
            // Set form data
            if (isset($data['form_data'])) {
                // Encode array to JSON string if it's an array
                if (is_array($data['form_data'])) {
                    $booking->setFormData(json_encode($data['form_data']));
                } else {
                    $booking->setFormData($data['form_data']);
                }
            }
            
            // Set assigned user if provided (from routing)
            if (isset($data['assigned_to'])) {
                $assignedUser = $this->entityManager->getRepository(UserEntity::class)->find($data['assigned_to']);
                if ($assignedUser) {
                    $booking->setAssignedTo($assignedUser);
                }
            }
            
            // Generate booking token
            $booking->setBookingToken($this->generateBookingToken());
            
            $this->entityManager->persist($booking);
            
            // Create guests if provided
            if (isset($data['guests']) && is_array($data['guests'])) {
                foreach ($data['guests'] as $guestData) {
                    $guest = new EventGuestEntity();
                    $guest->setBooking($booking);
                    $guest->setName($guestData['name'] ?? '');
                    $guest->setEmail($guestData['email'] ?? '');
                    $this->entityManager->persist($guest);
                }
            }
            
            // Create contact if primary contact provided
            if (isset($data['form_data']['primary_contact'])) {
                $contactData = $data['form_data']['primary_contact'];
                $this->contactService->createOrUpdateFromBooking(
                    $booking,
                    $contactData['email'] ?? '',
                    $contactData['name'] ?? '',
                    $contactData['phone'] ?? null
                );
            }
            
            $this->entityManager->flush();
            
            // Queue reminders for confirmed bookings
            if ($booking->getStatus() === 'confirmed') {
                try {
                    $this->reminderService->queueRemindersForBooking($booking);
                } catch (\Exception $e) {
                    // Log but don't fail if reminders fail
                    error_log('Failed to queue reminders: ' . $e->getMessage());
                }
            }
            
            // Trigger workflow if exists
            try {
               
            } catch (\Exception $e) {
                // Log but don't fail if workflow fails
                error_log('Failed to trigger workflows: ' . $e->getMessage());
            }
            
            return $booking;
            
        } catch (\Exception $e) {
            throw new EventsException('Failed to create booking: ' . $e->getMessage());
        }
    }

    /**
     * Generate unique booking token
     */
    private function generateBookingToken(): string
    {
        return bin2hex(random_bytes(16));
    }



    public function update(EventBookingEntity $booking, array $data): void
    {
        try {
            // Check if status is being changed to a cancellation state
            $isCancellation = false;
            if (!empty($data['status']) && 
                in_array($data['status'], ['cancelled', 'canceled', 'removed', 'deleted']) && 
                $booking->getStatus() !== $data['status']) {
                $isCancellation = true;
            }
            
            // Track if booking was previously cancelled
            $wasPreviouslyCancelled = $booking->isCancelled();
            
            // Update times if provided
            $timesChanged = false;
            if (!empty($data['start_time']) && !empty($data['end_time'])) {
                $startTime = $data['start_time'] instanceof \DateTimeInterface 
                    ? $data['start_time'] 
                    : new \DateTime($data['start_time']);
                    
                $endTime = $data['end_time'] instanceof \DateTimeInterface 
                    ? $data['end_time'] 
                    : new \DateTime($data['end_time']);
                
                if ($startTime >= $endTime) {
                    throw new EventsException('End time must be after start time');
                }
                
                // Only check availability if times are changing
                if ($startTime != $booking->getStartTime() || $endTime != $booking->getEndTime()) {
                    $timesChanged = true;
                    
                    // Check if the new slot is available, excluding this booking
                    if (!$this->scheduleService->isTimeSlotAvailableForAll(
                        $booking->getEvent(), 
                        $startTime, 
                        $endTime, 
                        null,
                        $booking->getId()
                    )) {
                        throw new EventsException('The selected time slot is not available');
                    }
                    
                    $booking->setStartTime($startTime);
                    $booking->setEndTime($endTime);
                }
            }
            
            // Update status if provided
            if (!empty($data['status'])) {
                $booking->setStatus($data['status']);
                
                // If status is a cancellation type, also set cancelled flag
                if (in_array($data['status'], ['cancelled', 'canceled', 'removed', 'deleted'])) {
                    $booking->setCancelled(true);
                }
            }
            
            // Update form data if provided
            if (!empty($data['form_data']) && is_array($data['form_data'])) {
                $booking->setFormDataFromArray($data['form_data']);
            }
            
            // Update cancellation status if explicitly provided
            if (isset($data['cancelled'])) {
                $booking->setCancelled((bool)$data['cancelled']);
                if ((bool)$data['cancelled'] && !$wasPreviouslyCancelled) {
                    $isCancellation = true;
                }
            }
            
            $this->entityManager->persist($booking);
            $this->entityManager->flush();
            
            // Update guests if provided
            if (!empty($data['guests']) && is_array($data['guests'])) {
                // Remove existing guests
                $existingGuests = $this->crudManager->findMany(
                    EventGuestEntity::class,
                    [],
                    1,
                    1000,
                    ['booking' => $booking]
                );
                    
                foreach ($existingGuests as $existingGuest) {
                    $this->entityManager->remove($existingGuest);
                }
                $this->entityManager->flush();
                
                // Add new guests
                foreach ($data['guests'] as $guestData) {
                    $this->addGuest($booking, $guestData);
                }
            }
            
            // Handle cancellation - updating availability and Google Calendar
            if ($isCancellation) {
                // Update availability records
                $this->scheduleService->handleBookingCancelled($booking);
                $this->reminderService->cancelRemindersForBooking($booking);



                // Delete from Google Calendar
                try {
                    // Get all assignees for this event
                    $event = $booking->getEvent();
                    $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
                        ->findBy(['event' => $event]);
                    
                    foreach ($assignees as $assignee) {
                        $user = $assignee->getUser();
                        
                        // Find Google Calendar integrations for this user
                        $integrations = $this->entityManager->getRepository('App\Plugins\Integrations\Common\Entity\IntegrationEntity')
                            ->findBy([
                                'user' => $user,
                                'provider' => 'google_calendar',
                                'status' => 'active'
                            ]);
                        
                        foreach ($integrations as $integration) {
                            try {
                                // Use the GoogleCalendarService to delete from Google
                                $this->googleCalendarService->deleteEventForCancelledBooking($integration, $booking);
                            } catch (\Exception $e) {
                                // Just silently catch the exception
                                // Don't let Google API errors stop us
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Silently catch the exception
                    // Don't let Google errors break our app
                }
            }
            // Handle time changes for non-cancelled bookings
            else if ($timesChanged && !$booking->isCancelled()) {
                $this->scheduleService->handleBookingUpdated($booking);
                $this->reminderService->updateRemindersForBooking($booking);
            }
        } catch (\Exception $e) {
            throw new EventsException('Failed to update booking: ' . $e->getMessage());
        }
    }

    public function cancel(EventBookingEntity $booking): void
    {
        try {
            $booking->setCancelled(true);
            $booking->setStatus('cancelled');
            
            $this->entityManager->persist($booking);
            $this->entityManager->flush();
            
            // Update availability records
            $this->scheduleService->handleBookingCancelled($booking);
            

            
            // Delete from Google Calendar using the GoogleCalendarService
            $this->googleCalendarService->deleteGoogleEventsForBooking($booking);
        } catch (\Exception $e) {
            throw new EventsException('Failed to cancel booking: ' . $e->getMessage());
        }
    }

    public function delete(EventBookingEntity $booking): void
    {
        try {
            // First cancel the booking if not already cancelled
            if (!$booking->isCancelled()) {
                $booking->setCancelled(true);
                
                // Update availability records
                $this->scheduleService->handleBookingCancelled($booking);
                
                // Delete from Google Calendar
                try {
                    $this->googleCalendarService->deleteGoogleEventsForBooking($booking);
                } catch (\Exception $e) {
                    // Silently catch exceptions
                }
            }
            
            // Remove related guests
            $guests = $this->crudManager->findMany(
                EventGuestEntity::class,
                [],
                1,
                1000,
                ['booking' => $booking]
            );
                
            foreach ($guests as $guest) {
                $this->entityManager->remove($guest);
            }
            
            // Remove the booking
            $this->entityManager->remove($booking);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new EventsException('Failed to delete booking: ' . $e->getMessage());
        }
    }
    
    /**
     * Add a guest to a booking
     */
    private function addGuest(EventBookingEntity $booking, array $guestData): EventGuestEntity
    {
        try {
            // Debug log
            error_log('Guest data: ' . json_encode($guestData));
            
            if (empty($guestData['email'])) {
                throw new EventsException('Guest must have an email');
            }
            
            $guest = new EventGuestEntity();
            $guest->setBooking($booking);
            $guest->setEmail($guestData['email']);
            
            // Name is optional - use email as name if not provided
            $guestName = isset($guestData['name']) && !empty($guestData['name']) 
                ? $guestData['name'] 
                : $guestData['email'];
                
            $guest->setName($guestName);
            
            if (!empty($guestData['phone'])) {
                $guest->setPhone($guestData['phone']);
            }
            
            $this->entityManager->persist($guest);
            $this->entityManager->flush();
            
            // Don't create contact here - remove this part entirely
            // Just return the guest
            
            return $guest;
        } catch (\Exception $e) {
            error_log('Failed to add guest: ' . $e->getMessage());
            throw new EventsException('Failed to add guest: ' . $e->getMessage());
        }
    }

    /**
     * Get bookings for an event within a date range
     */
    public function getBookingsByEvent(EventEntity $event, array $filters = [], bool $includeCancelled = false): array
    {
        $criteria = ['event' => $event];
        
        if (!$includeCancelled) {
            $criteria['cancelled'] = false;
        }
        
        return $this->getMany($filters, 1, 1000, $criteria);
    }
    
    /**
     * Get bookings for an event within a date range
     */
    public function getBookingsByDateRange(EventEntity $event, \DateTimeInterface $startDate, \DateTimeInterface $endDate, bool $includeCancelled = false): array
    {
        try {
            $criteria = [
                'event' => $event
            ];
            
            if (!$includeCancelled) {
                $criteria['cancelled'] = false;
            }
            
            return $this->crudManager->findMany(
                EventBookingEntity::class,
                [
                    [
                        'field' => 'startTime',
                        'operator' => 'greater_than_or_equal',
                        'value' => $startDate
                    ],
                    [
                        'field' => 'startTime',
                        'operator' => 'less_than_or_equal',
                        'value' => $endDate
                    ]
                ],
                1,
                1000,
                $criteria
            );
        } catch (CrudException $e) {
            throw new EventsException($e->getMessage());
        }
    }
    
    /**
     * Get guests for a booking
     */
    public function getGuests(EventBookingEntity $booking): array
    {
        try {
            return $this->crudManager->findMany(
                EventGuestEntity::class,
                [],
                1,
                1000,
                ['booking' => $booking]
            );
        } catch (CrudException $e) {
            throw new EventsException($e->getMessage());
        }
    }

    /**
     * Sync cancelled booking with Google Calendar integrations
     */
    public function syncCancellationWithGoogle(EventBookingEntity $booking): void
    {
        try {
            // Get the event
            $event = $booking->getEvent();
            
            // Get all assignees for this event
            $assignees = $this->entityManager->getRepository('App\Plugins\Events\Entity\EventAssigneeEntity')
                ->findBy(['event' => $event]);
            
            // Process each assignee
            foreach ($assignees as $assignee) {
                // Get the user
                $user = $assignee->getUser();
                
                // Find any Google Calendar integrations for this user
                $integrations = $this->entityManager->getRepository('App\Plugins\Integrations\Common\Entity\IntegrationEntity')
                    ->findBy([
                        'user' => $user,
                        'provider' => 'google_calendar',
                        'status' => 'active'
                    ]);
                
                // Process each integration
                foreach ($integrations as $integration) {
                    try {
                        // Delete the event from Google Calendar
                        $this->googleCalendarService->deleteEventForCancelledBooking($integration, $booking);
                    } catch (\Exception $e) {
                        // Silently catch exceptions
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently catch exceptions
        }
    }   

    /**
     * Sync a Skedi event booking to Calendar systems
     * 
     * @param EventBookingEntity $booking The booking to sync
     * @param UserEntity|null $specificUser Optional specific user to sync for
     * @return array Results of the sync operation
     */
    public function syncEventBooking(
        \App\Plugins\Events\Entity\EventBookingEntity $booking, 
        ?\App\Plugins\Account\Entity\UserEntity $specificUser = null
    ): array {
        $results = [
            'success' => 0,
            'failure' => 0,
            'skipped' => 0,
            'providers' => [],
            'debug_info' => [
                'google' => [],
                'general' => []
            ]
        ];
        
        try {
            $event = $booking->getEvent();
            $title = $event->getName();
            
            // Extract meeting info from form data if it exists
            $formData = $booking->getFormDataAsArray() ?: [];
            $calendarCreatorUserId = $formData['calendar_creator_user_id'] ?? null;
            $meetingInfo = $formData['online_meeting'] ?? null;
            $meetLink = null;
            $meetId = null;
            
            if ($meetingInfo && isset($meetingInfo['provider']) && $meetingInfo['provider'] === 'google_meet') {
                $meetLink = $meetingInfo['link'] ?? null;
                $meetId = $meetingInfo['id'] ?? null;
            }
            
            // Format description with booking details
            $description = "Booking for: {$title}\n";
            
            // Add booking form data if available
            if ($formData) {
                $description .= "\nBooking details:\n";
                foreach ($formData as $key => $value) {
                    if ($key !== 'online_meeting' && is_scalar($value)) {
                        $description .= "- " . ucfirst(str_replace('_', ' ', $key)) . ": " . 
                            (is_string($value) ? $value : json_encode($value)) . "\n";
                    }
                }
            }
            
            // Add meeting link to description if available
            if ($meetLink) {
                $description .= "\nJoin meeting: {$meetLink}\n";
            }
            
            // Get all assignees for this event
            $criteria = ['event' => $event];
            if ($specificUser) {
                $criteria['user'] = $specificUser;
            }
            
            $assignees = $this->crudManager->findMany(
                'App\Plugins\Events\Entity\EventAssigneeEntity',
                [],
                1,
                1000,
                $criteria
            );
            
            // Prepare attendees from booking guests
            $attendees = [];
            $guests = $this->crudManager->findMany(
                'App\Plugins\Events\Entity\EventGuestEntity',
                [],
                1,
                100,
                ['booking' => $booking]
            );
            
            foreach ($guests as $guest) {
                $attendees[] = [
                    'email' => $guest->getEmail(),
                    'name' => $guest->getName()
                ];
            }

     
            

            
            // Process each assignee
            foreach ($assignees as $assignee) {
                $user = $assignee->getUser();

                // SKIP if this user already created a calendar event with Meet
                if ($calendarCreatorUserId && $user->getId() == $calendarCreatorUserId) {
                    $results['skipped']++;
                    continue;
                }
                
                // Get active Google Calendar integrations for this user
                $googleIntegrations = $this->crudManager->findMany(
                    'App\Plugins\Integrations\Common\Entity\IntegrationEntity',
                    [],
                    1,
                    10,
                    [
                        'user' => $user,
                        'provider' => 'google_calendar',
                        'status' => 'active'
                    ]
                );
                
                if (!empty($googleIntegrations)) {
                    $integration = $googleIntegrations[0];
                    
                    try {
                        // Create options for Google Calendar event
                        $options = [
                            'description' => $description,
                            'attendees' => $attendees,
                            'source_id' => 'booking_' . $booking->getId()
                        ];
                        
                        // IMPORTANT: Check if we have a Meet link from the same Google account
                        if ($meetLink && $meetId) {
                            // Check if this Meet link was created by the same user
                            $meetEvent = $this->entityManager->getRepository('App\Plugins\Integrations\Google\Meet\Entity\GoogleMeetEventEntity')
                                ->findOneBy(['meetId' => $meetId]);
                            
                            if ($meetEvent && $meetEvent->getUser()->getId() === $user->getId()) {
                                // This user created the Meet link, so we should attach it to their calendar event
                                $options['conference_data'] = [
                                    'type' => 'existingMeet',
                                    'meetId' => $meetId
                                ];
                            } else {
                                // Different user, just include the link in description
                                // (Google won't let us attach another user's Meet link)
                            }
                        }
                        
                        // Create the event in Google Calendar
                        $createdEvent = $this->googleCalendarService->createCalendarEvent(
                            $integration,
                            $title,
                            $booking->getStartTime(),
                            $booking->getEndTime(),
                            $options
                        );
                        
                        $results['providers']['google_calendar']['success']++;
                        $results['success']++;
                    } catch (\Exception $e) {
                        $results['providers']['google_calendar']['failure']++;
                        $results['failure']++;
                    }
                }
            }
            
            return $results;
        } catch (\Exception $e) {
            return $results;
        }
    }

    
    private function generateMeetingLink(EventBookingEntity $booking, array $data): ?array
    {
        try {
            $event = $booking->getEvent();
            $location = $event->getLocation();
            
            // Return early if no location
            if (!is_array($location) || empty($location)) {
                return null;
            }
            
     
            $locationConfig = null;
            if (isset($location['type'])) {
                // Direct object: {"type": "google_meet", "integration_id": 26}
                $locationConfig = $location;
            } elseif (isset($location[0]) && isset($location[0]['type'])) {
                // Array of objects: [{"type": "google_meet", "integration_id": 26}]
                $locationConfig = $location[0];
            } else {
                return null;
            }

 
            // Meeting info to return
            $meetingInfo = null;
            
            // Handle different meeting types
            switch ($locationConfig['type']) {
                case 'google_meet':
                    $meetingInfo = $this->generateGoogleMeetLink($booking, $locationConfig, $data);
                    break;
                    
                case 'zoom':
                    // Future implementation
                    break;
                    
                case 'teams':
                    // Future implementation
                    break;
            }
            
            return $meetingInfo;
        } catch (\Exception $e) {
            // Return null on any error
            return null;
        }
    }


    private function generateGoogleMeetLink(EventBookingEntity $booking, array $location, array $data): ?array
    {
        try {
            // Get integration_id from location if provided
            $integrationId = $location['integration_id'] ?? null;
            $integration = null;
            
            if ($integrationId) {
                // Try to find the specified integration
                $integration = $this->entityManager->getRepository('App\Plugins\Integrations\Common\Entity\IntegrationEntity')
                    ->find($integrationId);
                
                // Verify it's a Google Meet or Google Calendar integration
                if ($integration && !in_array($integration->getProvider(), ['google_meet', 'google_calendar'])) {
                    $integration = null;
                }
            }
            
            if (!$integration) {
                // Try to get event creator's Google Meet integration
                $creator = $booking->getEvent()->getCreatedBy();
                $integration = $this->googleMeetService->getUserIntegration($creator);
                
                // If no Google Meet integration, try Google Calendar
                if (!$integration) {
                    $integration = $this->entityManager->getRepository('App\Plugins\Integrations\Common\Entity\IntegrationEntity')
                        ->findOneBy([
                            'user' => $creator,
                            'provider' => 'google_calendar',
                            'status' => 'active'
                        ]);
                }
            }
            
            if (!$integration) {
                return null;
            }
            
            // Generate title for the meeting - CHANGED: Removed date/time from title
            $event = $booking->getEvent();
            $title = $event->getName(); // Just the event name, no date/time
            
            // Create description with booking info
            $description = "Scheduled booking for " . $event->getName();
            if (!empty($data['form_data']) && is_array($data['form_data'])) {
                $description .= "\n\nParticipant information:";
                foreach ($data['form_data'] as $key => $value) {
                    if (is_scalar($value) && !in_array($key, ['online_meeting', 'booking_timezone'])) {
                        $description .= "\n" . ucfirst(str_replace('_', ' ', $key)) . ": " . $value;
                    }
                }
            }
            
            // Get guests to include as attendees
            $attendees = [];
            if (!empty($data['guests']) && is_array($data['guests'])) {
                foreach ($data['guests'] as $guest) {
                    if (isset($guest['email'])) {
                        $attendees[] = [
                            'email' => $guest['email'],
                            'name' => $guest['name'] ?? ''
                        ];
                    }
                }
            }
            
            // Also add primary contact as attendee if exists and not already in guests
            if (!empty($data['form_data']['primary_contact'])) {
                $primaryContact = $data['form_data']['primary_contact'];
                if (!empty($primaryContact['email'])) {
                    // Check if not already in attendees
                    $emailExists = false;
                    foreach ($attendees as $attendee) {
                        if ($attendee['email'] === $primaryContact['email']) {
                            $emailExists = true;
                            break;
                        }
                    }
                    
                    if (!$emailExists) {
                        $attendees[] = [
                            'email' => $primaryContact['email'],
                            'name' => $primaryContact['name'] ?? ''
                        ];
                    }
                }
            }
            
            // Create Google Meet link only (no calendar event)
            $meetEvent = $this->googleMeetService->createMeetLink(
                $integration,
                $title, // Now just the event name without date/time
                $booking->getStartTime(),
                $booking->getEndTime(),
                $event->getId(),
                $booking->getId(),
                [
                    'description' => $description,
                    'is_guest_allowed' => true,
                    'enable_recording' => false,
                    'attendees' => $attendees
                ]
            );
            
            // Return meeting info
            return [
                'provider' => 'google_meet',
                'link' => $meetEvent->getMeetLink(),
                'id' => $meetEvent->getMeetId(),
                'created_at' => $meetEvent->getCreated()->format('Y-m-d H:i:s'),
                'calendar_creator_user_id' => $integration->getUser()->getId() // ADD THIS
            ];
        } catch (\Exception $e) {
            // Silently fail - don't break the booking process
            return null;
        }
    }

}