<?php

namespace App\Plugins\Events\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Service\CrudManager;
use App\Plugins\Events\Service\EventService;
use App\Plugins\Events\Service\RoutingService;
use App\Plugins\Events\Service\EventAssigneeService;
use App\Plugins\Events\Service\EventScheduleService;
use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Entity\EventAssigneeEntity;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api/public')]
class EventRoutingController extends AbstractController
{
    private ResponseService $responseService;
    private CrudManager $crudManager;
    private EventService $eventService;
    private RoutingService $routingService;
    private EventAssigneeService $assigneeService;
    private EventScheduleService $scheduleService;
    private EntityManagerInterface $entityManager;

    public function __construct(
        ResponseService $responseService,
        CrudManager $crudManager,
        EventService $eventService,
        RoutingService $routingService,
        EventAssigneeService $assigneeService,
        EventScheduleService $scheduleService,
        EntityManagerInterface $entityManager
    ) {
        $this->responseService = $responseService;
        $this->crudManager = $crudManager;
        $this->eventService = $eventService;
        $this->routingService = $routingService;
        $this->assigneeService = $assigneeService;
        $this->scheduleService = $scheduleService;
        $this->entityManager = $entityManager;
    }

    /**
     * Get AI routing assignment for a booking
     * Public endpoint called from the calendar frontend before creating a booking
     */
    #[Route('/events/{event_id}/get-routing-assignment', name: 'event_routing_assignment_public', methods: ['POST'], requirements: ['event_id' => '\d+'])]
    public function getRoutingAssignment(int $event_id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        
        try {
            // Get event by ID (public endpoint, no auth required)
            $event = $this->crudManager->findOne(EventEntity::class, $event_id);
            
            if (!$event) {
                return $this->responseService->json(false, 'Event not found.');
            }
            
            // Check if routing is enabled
            if (!$event->getRoutingEnabled()) {
                // Return null assignment - booking will proceed without specific assignment
                return $this->responseService->json(true, 'Routing not enabled.', [
                    'assigned_to' => null,
                    'routing_method' => 'disabled'
                ]);
            }
            
            // Parse the requested time
            $startTime = new DateTime($data['start_time']);
            $endTime = new DateTime($data['end_time']);
            
            // Get all assignees for the event
            $allAssignees = $this->assigneeService->getAssigneesByEvent($event);
            
            if (empty($allAssignees)) {
                // No assignees configured - fallback to event creator
                return $this->responseService->json(true, 'No assignees configured.', [
                    'assigned_to' => $event->getCreatedBy() ? $event->getCreatedBy()->getId() : null,
                    'assigned_name' => $event->getCreatedBy() ? $event->getCreatedBy()->getName() : null,
                    'routing_method' => 'creator_fallback'
                ]);
            }
            
            // Filter available assignees based on their availability at the requested time
            $availableAssignees = $this->filterAvailableAssignees($allAssignees, $startTime, $endTime);
            
            if (empty($availableAssignees)) {
                // No one is available at this time - this shouldn't happen as slots shouldn't be shown
                return $this->responseService->json(false, 'No team members available at the selected time.');
            }
            
            // If only one assignee is available, assign to them
            if (count($availableAssignees) === 1) {
                $assignee = $availableAssignees[0];
                return $this->responseService->json(true, 'Single assignee available.', [
                    'assigned_to' => $assignee->getUser()->getId(),
                    'assigned_name' => $assignee->getUser()->getName(),
                    'routing_method' => 'single_available'
                ]);
            }
            
            // Parse form data to send to AI
            $formData = [];
            if (isset($data['form_data'])) {
                // Parse the JSON string if it's a string
                if (is_string($data['form_data'])) {
                    $formData = json_decode($data['form_data'], true);
                } else {
                    $formData = $data['form_data'];
                }
            }
            
            // Add time context to form data
            $formData['requested_time'] = $startTime->format('Y-m-d H:i:s');
            $formData['duration_minutes'] = round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);
            $formData['day_of_week'] = $startTime->format('l');
            $formData['time_of_day'] = $startTime->format('H:i');
            
            // Route with AI
            $assignedAssignee = $this->routingService->routeBooking($event, $formData, $startTime);
            
            if ($assignedAssignee) {
                return $this->responseService->json(true, 'AI routing successful.', [
                    'assigned_to' => $assignedAssignee->getUser()->getId(),
                    'assigned_name' => $assignedAssignee->getUser()->getName(),
                    'routing_method' => 'ai_routing'
                ]);
            }
            
            // AI routing failed or returned null - use fallback
            // Assign to least busy assignee
            $leastBusyAssignee = $this->findLeastBusyAssignee($availableAssignees, $startTime);
            
            if ($leastBusyAssignee) {
                return $this->responseService->json(true, 'Assigned to least busy team member.', [
                    'assigned_to' => $leastBusyAssignee->getUser()->getId(),
                    'assigned_name' => $leastBusyAssignee->getUser()->getName(),
                    'routing_method' => 'least_busy_fallback'
                ]);
            }
            
            // Final fallback - assign to event creator
            return $this->responseService->json(true, 'Fallback to event creator.', [
                'assigned_to' => $event->getCreatedBy() ? $event->getCreatedBy()->getId() : null,
                'assigned_name' => $event->getCreatedBy() ? $event->getCreatedBy()->getName() : null,
                'routing_method' => 'creator_fallback'
            ]);
            
        } catch (\Exception $e) {
            // Log error and return fallback
            error_log('AI routing error: ' . $e->getMessage());
            
            // Don't fail the booking - return null assignment
            return $this->responseService->json(true, 'Routing error - proceeding without assignment.', [
                'assigned_to' => null,
                'routing_method' => 'error_fallback',
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Filter assignees by availability at the requested time
     */
    private function filterAvailableAssignees(array $assignees, DateTime $startTime, DateTime $endTime): array
    {
        $available = [];
        
        foreach ($assignees as $assignee) {
            $user = $assignee->getUser();
            
            // Check if user has any conflicting bookings
            $hasConflict = $this->checkUserConflicts($user, $startTime, $endTime);
            
            if (!$hasConflict) {
                $available[] = $assignee;
            }
        }
        
        return $available;
    }
    
    /**
     * Check if user has conflicting bookings
     */
    private function checkUserConflicts($user, DateTime $startTime, DateTime $endTime): bool
    {
        // Query existing bookings for this user in the time range
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(b.id)')
            ->from(EventBookingEntity::class, 'b')
            ->where('b.assignedTo = :user')
            ->andWhere('b.cancelled = false')
            ->andWhere('(
                (b.startTime <= :start AND b.endTime > :start) OR
                (b.startTime < :end AND b.endTime >= :end) OR
                (b.startTime >= :start AND b.endTime <= :end)
            )')
            ->setParameter('user', $user)
            ->setParameter('start', $startTime)
            ->setParameter('end', $endTime);
        
        $count = (int) $qb->getQuery()->getSingleScalarResult();
        
        return $count > 0;
    }
    
    /**
     * Find the least busy assignee for the week
     */
    private function findLeastBusyAssignee(array $assignees, DateTime $requestedTime): ?EventAssigneeEntity
    {
        $startOfWeek = new DateTime('monday this week');
        $endOfWeek = new DateTime('sunday this week 23:59:59');
        
        $leastBusy = null;
        $minBookings = PHP_INT_MAX;
        
        foreach ($assignees as $assignee) {
            $user = $assignee->getUser();
            
            // Count bookings for this week
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(b.id)')
                ->from(EventBookingEntity::class, 'b')
                ->where('b.assignedTo = :user')
                ->andWhere('b.cancelled = false')
                ->andWhere('b.startTime BETWEEN :start AND :end')
                ->setParameter('user', $user)
                ->setParameter('start', $startOfWeek)
                ->setParameter('end', $endOfWeek);
            
            $bookingCount = (int) $qb->getQuery()->getSingleScalarResult();
            
            if ($bookingCount < $minBookings) {
                $minBookings = $bookingCount;
                $leastBusy = $assignee;
            }
        }
        
        return $leastBusy;
    }
}