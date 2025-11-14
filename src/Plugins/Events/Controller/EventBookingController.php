<?php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Events\Service\EventBookingService;
use App\Plugins\Events\Exception\EventsException;
use App\Plugins\Organizations\Service\UserOrganizationService;
use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Email\Service\EmailService;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Service\EventAssigneeService;
use App\Plugins\Events\Service\BookingReminderService;
use App\Plugins\Contacts\Service\ContactService;
use App\Plugins\Forms\Service\FormService;
use App\Plugins\Forms\Entity\FormSubmissionEntity;
use App\Plugins\Events\Service\RoutingService;

#[Route('/api/organizations/{organization_id}', requirements: ['organization_id' => '\d+'])]
class EventBookingController extends AbstractController
{
    private ResponseService $responseService;
    private EventService $eventService;
    private EventBookingService $bookingService;
    private ContactService $contactService;
    private UserOrganizationService $userOrganizationService;
    private EntityManagerInterface $entityManager;
    private EmailService $emailService;
    private EventAssigneeService $assigneeService;
    private BookingReminderService $reminderService;
    private FormService $formService;
    private RoutingService $routingService;

    public function __construct(
        ResponseService $responseService,
        EventService $eventService,
        EventBookingService $bookingService,
        ContactService $contactService,
        UserOrganizationService $userOrganizationService,
        EntityManagerInterface $entityManager,
        EmailService $emailService,
        BookingReminderService $reminderService,
        EventAssigneeService $assigneeService,
        FormService $formService,
        RoutingService $routingService
    ) {
        $this->responseService = $responseService;
        $this->eventService = $eventService;
        $this->bookingService = $bookingService;
        $this->contactService = $contactService;
        $this->userOrganizationService = $userOrganizationService;
        $this->entityManager = $entityManager;
        $this->emailService = $emailService;
        $this->assigneeService = $assigneeService;
        $this->reminderService = $reminderService;
        $this->formService = $formService;
        $this->routingService = $routingService;
    }

    #[Route('/events/{event_id}/bookings', name: 'event_bookings_get_many#', methods: ['GET'], requirements: ['event_id' => '\d+'])]
    public function getBookings(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $filters = $request->attributes->get('filters');
        $page = $request->attributes->get('page');
        $limit = $request->attributes->get('limit');

        try {
            // Check if user has access to this organization
            if (!$organization = $this->userOrganizationService->getOrganizationByUser($organization_id, $user)) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            // Get event by ID ensuring it belongs to the organization
            if (!$event = $this->eventService->getEventByIdAndOrganization($event_id, $organization->entity)) {
                return $this->responseService->json(false, 'Event was not found.');
            }

            // Pass event in criteria (4th parameter), not filters
            $bookings = $this->bookingService->getMany($filters, $page, $limit, ['event' => $event]);
            
            // Enrich each booking with guests, hosts, and other data
            $enrichedBookings = [];
            foreach ($bookings as $booking) {
                $bookingData = $booking->toArray();
                
                // Add guests
                $guests = $this->bookingService->getGuests($booking);
                $bookingData['guests'] = array_map(function($guest) {
                    return $guest->toArray();
                }, $guests);
                
                // Add hosts from event assignees
                $assignees = $this->assigneeService->getAssigneesByEvent($event);
                $hosts = [];
                foreach ($assignees as $assignee) {
                    $assigneeUser = $assignee->getUser();
                    if ($assigneeUser) {
                        $hosts[] = [
                            'id' => $assigneeUser->getId(),
                            'name' => $assigneeUser->getName(),
                            'email' => $assigneeUser->getEmail()
                        ];
                    }
                }
                $bookingData['hosts'] = $hosts;
                
                // Add event information
                $bookingData['event_id'] = $event->getId();
                $bookingData['event_name'] = $event->getName();
                $bookingData['organization_id'] = $organization->entity->getId();
                
                $enrichedBookings[] = $bookingData;
            }
            
            return $this->responseService->json(true, 'Bookings retrieved successfully.', $enrichedBookings);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/stats', name: 'event_bookings_stats#', methods: ['GET'], requirements: ['event_id' => '\d+'])]
    public function getBookingStats(int $organization_id, int $event_id, Request $request): JsonResponse
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

            // Get all bookings for this event - pass event in criteria
            $allBookings = $this->bookingService->getMany([], 1, 10000, ['event' => $event]);

            // Calculate statistics
            $stats = [
                'total' => 0,
                'upcoming' => 0,
                'past' => 0,
                'canceled' => 0,
                'pending' => 0
            ];

            $now = new \DateTime();

            foreach ($allBookings as $booking) {
                $stats['total']++;

                // Check if cancelled
                if ($booking->isCancelled() || $booking->getStatus() === 'canceled') {
                    $stats['canceled']++;
                    continue;
                }

                // Check status
                $status = $booking->getStatus();
                
                if ($status === 'pending') {
                    $stats['pending']++;
                } elseif ($status === 'confirmed') {
                    // Check if upcoming or past
                    $startTime = $booking->getStartTime();
                    if ($startTime > $now) {
                        $stats['upcoming']++;
                    } else {
                        $stats['past']++;
                    }
                }
            }

            return $this->responseService->json(true, 'Booking statistics retrieved successfully.', $stats);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }




    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_get_one', methods: ['GET'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function getBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
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

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Verify booking belongs to the event
            if ($booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            $bookingData = $booking->toArray();
            
            // Add guests
            $guests = $this->bookingService->getGuests($booking);
            $bookingData['guests'] = array_map(function($guest) {
                return $guest->toArray();
            }, $guests);
            
            return $this->responseService->json(true, 'Booking retrieved successfully.', $bookingData);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }


    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_update', methods: ['PUT'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function updateBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = json_decode($request->getContent(), true);

        try {
            // Get event first
            $event = $this->eventService->getOne($event_id);
            
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Get organization from event
            $organization = $event->getOrganization();
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            if ($organization->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Event does not belong to the specified organization.');
            }

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Verify booking belongs to the event
            if ($booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Capture previous status for email logic
            $previousStatus = $booking->getStatus();
            $wasPreviouslyCancelled = $booking->isCancelled();

            // Update the booking
            $this->bookingService->update($booking, $data);
            
            // Handle status change from pending to confirmed
            if (isset($data['status']) && $data['status'] === 'confirmed' && $booking->getStatus() === 'confirmed' && $previousStatus === 'pending') {
                $this->sendBookingConfirmedEmailToGuest($booking);
                $this->sendBookingEmails($booking);
            }
            
            // Handle cancellation
            if (!$wasPreviouslyCancelled && $booking->isCancelled()) {
                try {
                    $this->sendBookingCancellationEmail($booking, $data['cancellation_reason'] ?? null);
                } catch (\Exception $e) {
                    error_log('Failed to send booking cancellation email: ' . $e->getMessage());
                }
            }
            
            $bookingData = $booking->toArray();
            
            // Add guests
            $guests = $this->bookingService->getGuests($booking);
            $bookingData['guests'] = array_map(function($guest) {
                return $guest->toArray();
            }, $guests);
            
            return $this->responseService->json(true, 'Booking updated successfully.', $bookingData);
            
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Send booking confirmed email to guest using booking_confirmed template
     */
    private function sendBookingConfirmedEmailToGuest($booking): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            $organization = $event->getOrganization();
            
            // Get guest info
            $guestEmail = $formData['primary_contact']['email'];
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            
            // Get meeting details
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            $organizer = $event->getCreatedBy();
            $organizerName = $organizer ? $organizer->getName() : 'Host';
            $location = $this->getEventLocation($event);
            $meetingLink = $formData['online_meeting']['link'] ?? '';
            
            // Generate frontend URLs
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';
            $orgSlug = $organization ? $organization->getSlug() : '';
            $eventSlug = $event->getSlug();
            $rescheduleLink = $frontendUrl . '/' . $orgSlug . '/schedule/' . $eventSlug;
            $cancelLink = $rescheduleLink;
            
            // Build email data
            $emailData = [
                'guest_name' => $guestName,
                'host_name' => $organizerName,
                'event_name' => $event->getName(),
                'event_date' => $startTime->format('F j, Y'),
                'event_time' => $startTime->format('g:i A'),
                'duration' => $duration . ' minutes',
                'location' => $location,
                'meeting_link' => $meetingLink,
                'calendar_link' => $rescheduleLink,
                'manage_link' => $frontendUrl . '/manage/' . $booking->getBookingToken(),
                'company_name' => $organization ? $organization->getName() : '',
                'booking_id' => $booking->getId(),
                'organization_id' => $organization ? $organization->getId() : null
            ];
            
            // *** USE QUEUE METHOD DIRECTLY (same as reminders) ***
            $queueResult = $this->emailService->queue(
                $guestEmail,
                'booking_confirmed',
                $emailData,
                [
                    'priority' => 8 // High priority for confirmation
                ]
            );
            
            // Log the result (same pattern as reminders)
            if ($queueResult['success'] && !empty($queueResult['queue_id'])) {
                error_log('SUCCESS: booking_confirmed email queued for guest: ' . $guestEmail . ' (queue_id: ' . $queueResult['queue_id'] . ')');
                
                // Update the queue record with booking reference (same as reminders)
                $queueItem = $this->entityManager->getRepository(\App\Plugins\Email\Entity\EmailQueueEntity::class)->find($queueResult['queue_id']);
                if ($queueItem) {
                    $queueItem->setBooking($booking);
                    $this->entityManager->flush();
                    error_log('Updated queue item with booking reference');
                }
            } else {
                error_log('FAILED: booking_confirmed email not queued - result: ' . json_encode($queueResult));
            }
            
        } catch (\Exception $e) {
            error_log('EXCEPTION in sendBookingConfirmedEmailToGuest: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
        }
    }

    
    #[Route('/events/{event_id}/bookings', name: 'event_bookings_create', methods: ['POST'], requirements: ['event_id' => '\d+'])]
    public function createBooking(int $organization_id, int $event_id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        try {
            // Get event first
            $event = $this->eventService->getOne($event_id);
            
            // Check if event exists BEFORE using it
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            // Get organization
            $organization = $event->getOrganization();
            
            if (!$organization) {
                return $this->responseService->json(false, 'Organization was not found.');
            }
            
            if ($organization->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Event does not belong to the specified organization.');
            }
            
            // NOW set the event_id
            $data['event_id'] = $event->getId();
            
            // Validate basic form data
            if (isset($data['form_data']) && is_string($data['form_data'])) {
                $data['form_data'] = json_decode($data['form_data'], true);
            }
            
            if (!isset($data['form_data']['primary_contact']['name']) || empty($data['form_data']['primary_contact']['name'])) {
                return $this->responseService->json(false, 'Name is required.', null, 400);
            }
            
            if (empty($data['form_data']['primary_contact']['email'])) {
                return $this->responseService->json(false, 'Email is required.', null, 400);
            }
            
            // Validate email format
            if (!filter_var($data['form_data']['primary_contact']['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->responseService->json(false, 'Invalid email format.', null, 400);
            }
            
            // ROUTING LOGIC
            $assignedTo = null;
            if ($event->getRoutingEnabled()) {
                try {
                    // Parse the datetime from booking data
                    $startTime = new \DateTime($data['start_time']);
                    
                    // Call routing service to determine assignee
                    $routedAssignee = $this->routingService->routeBooking(
                        $event,
                        $data['form_data'], // The form data from the booking
                        $startTime
                    );
                    
                    if ($routedAssignee) {
                        $assignedTo = $routedAssignee->getUser();
                        // Add assigned user to booking data
                        $data['assigned_to'] = $assignedTo->getId();
                    }
                } catch (\Exception $e) {
                    // Log routing error but don't fail booking
                    // Continue without routing - will use default behavior
                }
            }
            
            // Create the booking (now includes assigned_to if routing was used)
            $booking = $this->bookingService->create($data);
            
            // If we have an assigned user, update the booking entity
            if ($assignedTo) {
                $booking->setAssignedTo($assignedTo);
                $this->entityManager->flush();
            }
            
            // Send emails (will respect routing assignment)
            $this->sendBookingEmails($booking);
            
            $bookingData = $booking->toArray();

            // Add booking token for redirect
            $bookingData['booking_token'] = $booking->getBookingToken();
            
            // Add assigned user info if routed
            if ($assignedTo) {
                $bookingData['assigned_to'] = [
                    'id' => $assignedTo->getId(),
                    'name' => $assignedTo->getName(),
                    'email' => $assignedTo->getEmail()
                ];
                $bookingData['routing_used'] = true;
            } else {
                $bookingData['routing_used'] = false;
            }

            // Add guests
            $guests = $this->bookingService->getGuests($booking);
            $bookingData['guests'] = array_map(function($guest) {
                return $guest->toArray();
            }, $guests);

            return $this->responseService->json(true, 'Booking created successfully.', $bookingData, 201);
            
        } catch (EventsException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    /**
     * ***  Queue emails  ***
    */
    private function sendBookingEmails($booking): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            $organization = $event->getOrganization();
            
            // Basic email data
            $guestEmail = $formData['primary_contact']['email'];
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $startTime = $booking->getStartTime();
            $organizer = $event->getCreatedBy();
            $organizerName = $organizer ? $organizer->getName() : 'Host';
            
            $emailData = [
                'guest_name' => $guestName,
                'host_name' => $organizerName,
                'meeting_name' => $event->getName(),
                'meeting_date' => $startTime->format('F j, Y'),
                'meeting_time' => $startTime->format('g:i A'),
                'meeting_status' => $booking->getStatus(),
                'booking_id' => $booking->getId(),
                'organization_id' => $organization->getId()
            ];
            
            if ($booking->getStatus() === 'pending') {
                // PENDING: Send approval request to hosts
                $assignees = $this->assigneeService->getAssigneesByEvent($event);
                foreach ($assignees as $assignee) {
                    $hostData = array_merge($emailData, [
                        'host_name' => $assignee->getUser()->getName()
                    ]);
                    
                    // Queue host approval email
                    $this->emailService->send(
                        $assignee->getUser()->getEmail(),
                        'meeting_scheduled_host',
                        $hostData
                    );
                }
            } else {
                // CONFIRMED: Send confirmation to both
                
                // Queue guest confirmation
                $this->emailService->send($guestEmail, 'meeting_scheduled', $emailData);
                
                // Queue host notifications
                $assignees = $this->assigneeService->getAssigneesByEvent($event);
                foreach ($assignees as $assignee) {
                    $hostData = array_merge($emailData, [
                        'host_name' => $assignee->getUser()->getName()
                    ]);
                    
                    $this->emailService->send(
                        $assignee->getUser()->getEmail(),
                        'meeting_scheduled_host',
                        $hostData
                    );
                }
                
                // Queue reminders
                if ($booking->getStatus() !== 'pending') {
                    try {
                        $this->reminderService->queueRemindersForBooking($booking);
                    } catch (\Exception $e) {
                        error_log('Failed to queue reminders for booking: ' . $e->getMessage());
                    }
                }
            }
            
        } catch (\Exception $e) {
            // Don't fail the booking if emails fail
            error_log('Failed to send booking emails: ' . $e->getMessage());
        }
    }


    #[Route('/events/{event_id}/bookings/{id}', name: 'event_bookings_delete', methods: ['DELETE'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function deleteBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
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

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Verify booking belongs to the event
            if ($booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            $this->bookingService->delete($id);
            
            return $this->responseService->json(true, 'Booking deleted successfully.');

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}/cancel', name: 'event_bookings_cancel', methods: ['POST'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function cancelBooking(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = json_decode($request->getContent(), true);

        try {
            // Get event first
            $event = $this->eventService->getOne($event_id);
            
            if (!$event) {
                return $this->responseService->json(false, 'Event was not found.');
            }
            
            $organization = $event->getOrganization();
            
            if (!$organization || $organization->getId() !== $organization_id) {
                return $this->responseService->json(false, 'Event does not belong to the specified organization.');
            }

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking || $booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Cancel the booking
            $cancelData = [
                'status' => 'canceled',
                'cancelled' => true,
                'cancellation_reason' => $data['reason'] ?? null,
                'cancelled_at' => new \DateTime(),
                'cancelled_by' => $user
            ];

            $this->bookingService->update($booking, $cancelData);
            
            // *** SEND CANCELLATION EMAIL ***
            $this->sendBookingCancellationEmail($booking, $data['reason'] ?? null);
            
            $bookingData = $booking->toArray();
            
            return $this->responseService->json(true, 'Booking cancelled successfully.', $bookingData);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    #[Route('/events/{event_id}/bookings/{id}/reminders', name: 'event_bookings_reminders', methods: ['POST'], requirements: ['event_id' => '\d+', 'id' => '\d+'])]
    public function scheduleReminder(int $organization_id, int $event_id, int $id, Request $request): JsonResponse
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

            $booking = $this->bookingService->getOne($id);
            
            if (!$booking) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Verify booking belongs to the event
            if ($booking->getEvent()->getId() !== $event->getId()) {
                return $this->responseService->json(false, 'Booking was not found.');
            }

            // Schedule reminder
            $reminderType = $data['type'] ?? '1_hour';
            $reminder = $this->reminderService->scheduleReminder($booking, $reminderType);
            
            return $this->responseService->json(true, 'Reminder scheduled successfully.', [
                'reminder_id' => $reminder->getId(),
                'scheduled_at' => $reminder->getScheduledAt()->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    
    
    /**
     * Send notification email to host(s)
     */
    private function sendHostNotificationEmail(EventBookingEntity $booking): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            
            // Get guest information
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $guestEmail = $formData['primary_contact']['email'];
            $guestPhone = $formData['primary_contact']['phone'] ?? '';
            $guestMessage = $formData['notes'] ?? '';
            
            // Get event details
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            
            // Get organization info
            $organization = $event->getOrganization();
            $companyName = $organization ? $organization->getName() : '';
            
            // Determine location
            $location = $this->getEventLocation($event);
            
            // Get meeting link if available
            $meetingLink = $formData['online_meeting']['link'] ?? '';
            
            // Generate frontend URLs
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';
            $orgSlug = $organization ? $organization->getSlug() : '';
            $eventSlug = $event->getSlug();
            $rescheduleLink = $frontendUrl . '/' . $orgSlug . '/schedule/' . $eventSlug;
            
            // Common email data for hosts
            $commonHostData = [
                'guest_name' => $guestName,
                'guest_email' => $guestEmail,
                'guest_phone' => $guestPhone,
                'guest_message' => $guestMessage,
                'meeting_name' => $event->getName(),
                'event_name' => $event->getName(),
                'meeting_date' => $startTime->format('F j, Y'),
                'meeting_time' => $startTime->format('g:i A'),
                'meeting_duration' => $duration . ' minutes',
                'duration' => $duration . ' minutes',
                'meeting_location' => $location,
                'location' => $location,
                'meeting_link' => $meetingLink,
                'company_name' => $companyName,
                'reschedule_link' => $rescheduleLink,
                'meeting_status' => $booking->getStatus(), 
                'booking_id' => $booking->getId(),
                'organization_id' => $organization ? $organization->getId() : null,
                'custom_fields' => $formData['custom_fields'] ?? []
            ];
            
            // **HOST EMAILS** - Send to all assignees
            $assignees = $this->assigneeService->getAssigneesByEvent($event);
            
            foreach ($assignees as $assignee) {
                $hostData = array_merge($commonHostData, [
                    'host_name' => $assignee->getUser()->getName()
                ]);
                
                $this->emailService->send(
                    $assignee->getUser()->getEmail(),
                    'meeting_scheduled_host', // Template handles status internally
                    $hostData
                );
            }
            
        } catch (\Exception $e) {
            // Log error but don't fail the booking
            error_log('Failed to send host notification email: ' . $e->getMessage());
            
            if ($_ENV['APP_DEBUG'] ?? false) {
                error_log('Host email error: ' . json_encode([
                    'booking_id' => $booking->getId(),
                    'status' => $booking->getStatus(),
                    'error' => $e->getMessage()
                ]));
            }
        }
    }
    
    /**
     * Helper method to determine event location
     */
    private function getEventLocation($event): string
    {
        $location = 'Online Meeting'; // Default
        $eventLocation = $event->getLocation();
        
        if ($eventLocation && is_array($eventLocation)) {
            // Check if it's a single location with 'type' key
            if (isset($eventLocation['type'])) {
                switch ($eventLocation['type']) {
                    case 'in_person':
                        $location = $eventLocation['address'] ?? 'In-Person Meeting';
                        break;
                    case 'phone':
                        $location = 'Phone Call';
                        break;
                    case 'google_meet':
                        $location = 'Google Meet';
                        break;
                    case 'zoom':
                        $location = 'Zoom Meeting';
                        break;
                    case 'custom':
                        $location = $eventLocation['label'] ?? 'Custom Location';
                        break;
                    default:
                        $location = 'Online Meeting';
                }
            }
            // Could be array of locations - use the first one
            elseif (is_array($eventLocation) && !empty($eventLocation[0])) {
                $firstLocation = $eventLocation[0];
                if (isset($firstLocation['type']) && $firstLocation['type'] === 'in_person') {
                    $location = $firstLocation['address'] ?? 'In-Person Meeting';
                } else {
                    $location = 'Online Meeting';
                }
            }
        }
        
        return $location;
    }


    /**
     * Send booking cancellation email to guest
     */
    private function sendBookingCancellationEmail(EventBookingEntity $booking, ?string $reason = null): void
    {
        try {
            $event = $booking->getEvent();
            $formData = $booking->getFormDataAsArray();
            
            // Get guest information
            $guestName = $formData['primary_contact']['name'] ?? 'Guest';
            $guestEmail = $formData['primary_contact']['email'];
            
            if (empty($guestEmail)) {
                return; // No email to send to
            }
            
            // Get event details
            $startTime = $booking->getStartTime();
            $duration = round(($booking->getEndTime()->getTimestamp() - $startTime->getTimestamp()) / 60);
            
            // Get organizer/creator info
            $organizer = $event->getCreatedBy();
            $organizerName = $organizer ? $organizer->getName() : 'Host';
            
            // Get organization info
            $organization = $event->getOrganization();
            $companyName = $organization ? $organization->getName() : '';
            
            // Determine location
            $location = $this->getEventLocation($event);
            
            // Generate rebook link
            $frontendUrl = $_ENV['FRONTEND_URL'] ?? 'https://app.skedi.com';
            $orgSlug = $organization ? $organization->getSlug() : '';
            $eventSlug = $event->getSlug();
            $rebookLink = $frontendUrl . '/' . $orgSlug . '/schedule/' . $eventSlug;
            
            // Send cancellation email
            $this->emailService->send(
                $guestEmail,
                'meeting_cancelled',
                [
                    'guest_name' => $guestName,
                    'host_name' => $organizerName,
                    'meeting_name' => $event->getName(),
                    'meeting_date' => $startTime->format('F j, Y'),
                    'meeting_time' => $startTime->format('g:i A'),
                    'meeting_duration' => $duration . ' minutes',
                    'meeting_location' => $location,
                    'company_name' => $companyName,
                    'cancellation_reason' => $reason,
                    'cancelled_by' => 'the host',
                    'rebook_link' => $rebookLink,
                    'booking_id' => $booking->getId(),
                    'organization_id' => $organization ? $organization->getId() : null
                ]
            );
            
        } catch (\Exception $e) {
            // Log error but don't fail the cancellation
            error_log('Failed to send booking cancellation email: ' . $e->getMessage());
        }
    }

}