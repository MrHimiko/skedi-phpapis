<?php

// File: src/Plugins/Events/Service/RoutingService.php

namespace App\Plugins\Events\Service;

use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Entity\EventAssigneeEntity;
use App\Plugins\Events\Service\EventAssigneeService;
use App\Plugins\Events\Service\EventScheduleService;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;

class RoutingService
{
    private EntityManagerInterface $entityManager;
    private EventAssigneeService $assigneeService;
    private EventScheduleService $scheduleService;
    private CrudManager $crudManager;
    private string $openaiApiKey;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        EventAssigneeService $assigneeService,
        EventScheduleService $scheduleService,
        CrudManager $crudManager,
        string $openaiApiKey
    ) {
        $this->entityManager = $entityManager;
        $this->assigneeService = $assigneeService;
        $this->scheduleService = $scheduleService;
        $this->crudManager = $crudManager;
        $this->openaiApiKey = $openaiApiKey;
    }
    
    /**
     * Route booking to appropriate assignee
     */
    public function routeBooking(
        EventEntity $event, 
        array $formData, 
        DateTime $requestedTime
    ): ?EventAssigneeEntity {
        // If routing not enabled, return null (use default behavior)
        if (!$event->getRoutingEnabled()) {
            return null;
        }
        
        // Get available assignees
        $assignees = $this->assigneeService->getAssigneesByEvent($event);
        $availableAssignees = $this->filterAvailableAssignees($assignees, $requestedTime);
        
        if (empty($availableAssignees)) {
            throw new \Exception('No team members available at selected time');
        }
        
        // If only one assignee available, return them
        if (count($availableAssignees) === 1) {
            return $availableAssignees[0];
        }
        
        // Try AI routing if instructions exist
        if ($event->getRoutingInstructions()) {
            try {
                $selectedAssignee = $this->routeWithAI(
                    $event,
                    $formData,
                    $availableAssignees,
                    $requestedTime
                );
                
                if ($selectedAssignee) {
                    return $selectedAssignee;
                }
            } catch (\Exception $e) {
                // Log AI failure, continue to fallback
                error_log('AI routing failed: ' . $e->getMessage());
            }
        }
        
        // Fallback routing
        return $this->applyFallbackRouting($event, $availableAssignees);
    }
    
    /**
     * Filter assignees by availability
     */
    private function filterAvailableAssignees(array $assignees, DateTime $requestedTime): array
    {
        $available = [];
        
        foreach ($assignees as $assignee) {
            // Check if user has availability at requested time
            // This should check calendar integrations, existing bookings, etc.
            // For MVP, let's assume everyone is available
            $available[] = $assignee;
        }
        
        return $available;
    }
    
    /**
     * Get booking history for the last 2 weeks for an event
     */
    private function getRecentBookingHistory(EventEntity $event): array
    {
        $twoWeeksAgo = new DateTime('-14 days');
        $now = new DateTime();
        
        $bookings = $this->crudManager->findMany(
            EventBookingEntity::class,
            [],
            1,
            500, // Get up to 500 recent bookings
            ['event' => $event],
            function($queryBuilder) use ($twoWeeksAgo, $now) {
                $queryBuilder
                    ->andWhere('t1.startTime >= :twoWeeksAgo')
                    ->andWhere('t1.startTime <= :now')
                    ->andWhere('t1.cancelled = :cancelled')
                    ->setParameter('twoWeeksAgo', $twoWeeksAgo)
                    ->setParameter('now', $now)
                    ->setParameter('cancelled', false)
                    ->orderBy('t1.startTime', 'DESC');
            }
        );
        
        $history = [];
        foreach ($bookings as $booking) {
            $assignedTo = $booking->getAssignedTo();
            $formData = $booking->getFormDataAsArray();
            
            $history[] = [
                'id' => $booking->getId(),
                'start_time' => $booking->getStartTime()->format('Y-m-d H:i'),
                'assigned_to_id' => $assignedTo ? $assignedTo->getId() : null,
                'assigned_to_name' => $assignedTo ? $assignedTo->getName() : null,
                'guest_name' => $formData['primary_contact']['name'] ?? null,
                'guest_email' => $formData['primary_contact']['email'] ?? null,
            ];
        }
        
        return $history;
    }
    
    /**
     * Get assignment distribution stats
     */
    private function getAssignmentDistribution(EventEntity $event, array $assignees): array
    {
        $twoWeeksAgo = new DateTime('-14 days');
        $distribution = [];
        
        foreach ($assignees as $assignee) {
            $user = $assignee->getUser();
            
            // Count bookings assigned to this user in the last 2 weeks
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('COUNT(b.id)')
                ->from(EventBookingEntity::class, 'b')
                ->where('b.event = :event')
                ->andWhere('b.assignedTo = :user')
                ->andWhere('b.startTime >= :twoWeeksAgo')
                ->andWhere('b.cancelled = :cancelled')
                ->setParameter('event', $event)
                ->setParameter('user', $user)
                ->setParameter('twoWeeksAgo', $twoWeeksAgo)
                ->setParameter('cancelled', false);
            
            $count = (int) $qb->getQuery()->getSingleScalarResult();
            
            $distribution[] = [
                'user_id' => $user->getId(),
                'user_name' => $user->getName(),
                'bookings_last_2_weeks' => $count
            ];
        }
        
        // Calculate percentages
        $total = array_sum(array_column($distribution, 'bookings_last_2_weeks'));
        foreach ($distribution as &$item) {
            $item['percentage'] = $total > 0 
                ? round(($item['bookings_last_2_weeks'] / $total) * 100, 1) 
                : 0;
        }
        
        return $distribution;
    }
    
    /**
     * Route using OpenAI
     */
    private function routeWithAI(
        EventEntity $event,
        array $formData,
        array $availableAssignees,
        DateTime $requestedTime
    ): ?EventAssigneeEntity {
        // Prepare assignee context
        $assigneeContext = [];
        foreach ($availableAssignees as $assignee) {
            $user = $assignee->getUser();
            $bookingsThisWeek = $this->countWeeklyBookings($user);
            
            $assigneeContext[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
                'role' => $assignee->getRole(),
                'meetings_this_week' => $bookingsThisWeek
            ];
        }
        
        // Get booking history and distribution
        $recentBookings = $this->getRecentBookingHistory($event);
        $distribution = $this->getAssignmentDistribution($event, $availableAssignees);
        
        // Build prompt with enhanced context
        $prompt = $this->buildAIPrompt(
            $event,
            $formData,
            $assigneeContext,
            $event->getRoutingInstructions(),
            $recentBookings,
            $distribution,
            $requestedTime
        );
        
        // Call OpenAI
        $response = $this->callOpenAI($prompt);
        
        // Parse response and find assignee
        if (isset($response['assignee_id'])) {
            foreach ($availableAssignees as $assignee) {
                if ($assignee->getUser()->getId() == $response['assignee_id']) {
                    // Log the routing decision
                    $this->logRoutingDecision(
                        $event,
                        null, // booking not created yet
                        $formData,
                        $response,
                        $assignee->getUser()->getId(),
                        'ai'
                    );
                    
                    return $assignee;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Build AI prompt with enhanced context
     */
    private function buildAIPrompt(
        EventEntity $event,
        array $formData,
        array $assignees,
        string $instructions,
        array $recentBookings,
        array $distribution,
        DateTime $requestedTime
    ): string {
        $prompt = "You are a meeting routing assistant for a scheduling platform. Your job is to assign incoming meeting requests to the most appropriate team member based on the provided instructions and context.\n\n";
        
        // Event info
        $prompt .= "=== EVENT INFORMATION ===\n";
        $prompt .= "Event: " . $event->getName() . "\n";
        $prompt .= "Requested Time: " . $requestedTime->format('Y-m-d H:i') . " (" . $requestedTime->format('l') . ")\n\n";
        
        // Form data from the booking request
        $prompt .= "=== BOOKING REQUEST (FORM DATA) ===\n";
        $prompt .= json_encode($formData, JSON_PRETTY_PRINT) . "\n\n";
        
        // Available assignees
        $prompt .= "=== AVAILABLE TEAM MEMBERS ===\n";
        $prompt .= json_encode($assignees, JSON_PRETTY_PRINT) . "\n\n";
        
        // Distribution stats
        $prompt .= "=== ASSIGNMENT DISTRIBUTION (Last 2 Weeks) ===\n";
        $prompt .= json_encode($distribution, JSON_PRETTY_PRINT) . "\n\n";
        
        // Recent booking history (limit to last 20 for prompt size)
        if (!empty($recentBookings)) {
            $prompt .= "=== RECENT BOOKINGS (Last 2 Weeks, up to 20) ===\n";
            $prompt .= json_encode(array_slice($recentBookings, 0, 20), JSON_PRETTY_PRINT) . "\n\n";
        }
        
        // Instructions
        $prompt .= "=== ROUTING INSTRUCTIONS ===\n";
        $prompt .= $instructions . "\n\n";
        
        $prompt .= "=== YOUR TASK ===\n";
        $prompt .= "Based on ALL the information above, select the single best team member to handle this meeting.\n";
        $prompt .= "Consider:\n";
        $prompt .= "1. The routing instructions (highest priority)\n";
        $prompt .= "2. Current workload distribution\n";
        $prompt .= "3. The form data / booking request details\n";
        $prompt .= "4. Recent booking patterns\n\n";
        
        $prompt .= "Return ONLY valid JSON with this exact format: {\"assignee_id\": <user_id_number>, \"reason\": \"brief explanation of your choice\"}\n";
        $prompt .= "Do not include any other text, just the JSON object.";
        
        return $prompt;
    }
    
    /**
     * Call OpenAI API
     */
    private function callOpenAI(string $prompt): array
    {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->openaiApiKey,
            'Content-Type: application/json'
        ]);
        
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.3,
            'max_tokens' => 200
        ]));
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 second timeout for AI routing
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            throw new \Exception('OpenAI API call failed');
        }
        
        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        // Parse the JSON response - handle potential markdown wrapping
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        
        $result = json_decode($content, true);
        if (!$result) {
            throw new \Exception('Invalid JSON response from AI: ' . $content);
        }
        
        return $result;
    }
    
    /**
     * Apply fallback routing strategy
     */
    private function applyFallbackRouting(EventEntity $event, array $availableAssignees): EventAssigneeEntity
    {
        $strategy = $event->getRoutingFallback() ?? 'round_robin';
        
        switch ($strategy) {
            case 'least_busy':
                return $this->pickLeastBusy($availableAssignees);
                
            case 'random':
                return $availableAssignees[array_rand($availableAssignees)];
                
            case 'round_robin':
            default:
                return $this->pickRoundRobin($availableAssignees);
        }
    }
    
    /**
     * Round-robin selection
     */
    private function pickRoundRobin(array $assignees): EventAssigneeEntity
    {
        // Sort by last_assigned_at
        usort($assignees, function($a, $b) {
            $aLast = $this->getLastAssignedTime($a);
            $bLast = $this->getLastAssignedTime($b);
            return $aLast <=> $bLast;
        });
        
        return $assignees[0];
    }
    
    /**
     * Pick least busy assignee
     */
    private function pickLeastBusy(array $assignees): EventAssigneeEntity
    {
        $leastBusy = null;
        $minBookings = PHP_INT_MAX;
        
        foreach ($assignees as $assignee) {
            $count = $this->countWeeklyBookings($assignee->getUser());
            if ($count < $minBookings) {
                $minBookings = $count;
                $leastBusy = $assignee;
            }
        }
        
        return $leastBusy;
    }
    
    /**
     * Get last assigned timestamp for an assignee
     */
    private function getLastAssignedTime(EventAssigneeEntity $assignee): int
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('MAX(b.created)')
            ->from(EventBookingEntity::class, 'b')
            ->where('b.assignedTo = :user')
            ->setParameter('user', $assignee->getUser())
            ->setMaxResults(1);
        
        $result = $qb->getQuery()->getSingleScalarResult();
        return $result ? strtotime($result) : 0;
    }
    
    /**
     * Count weekly bookings for a user
     */
    private function countWeeklyBookings($user): int
    {
        $startOfWeek = new DateTime('monday this week');
        $endOfWeek = new DateTime('sunday this week 23:59:59');
        
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('COUNT(b.id)')
            ->from(EventBookingEntity::class, 'b')
            ->where('b.assignedTo = :user')
            ->andWhere('b.startTime BETWEEN :start AND :end')
            ->setParameter('user', $user)
            ->setParameter('start', $startOfWeek)
            ->setParameter('end', $endOfWeek);
        
        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    
    /**
     * Log routing decision
     */
    private function logRoutingDecision(
        EventEntity $event,
        ?EventBookingEntity $booking,
        array $formData,
        array $aiResponse,
        int $assignedTo,
        string $method
    ): void {
        try {
            $sql = "INSERT INTO event_routing_log 
                    (event_id, booking_id, form_data, ai_response, assigned_to, routing_method, created_at) 
                    VALUES (:event_id, :booking_id, :form_data, :ai_response, :assigned_to, :method, NOW())";
            
            $stmt = $this->entityManager->getConnection()->prepare($sql);
            $stmt->executeStatement([
                'event_id' => $event->getId(),
                'booking_id' => $booking ? $booking->getId() : null,
                'form_data' => json_encode($formData),
                'ai_response' => json_encode($aiResponse),
                'assigned_to' => $assignedTo,
                'method' => $method
            ]);
        } catch (\Exception $e) {
            // Log but don't fail
            error_log('Failed to log routing decision: ' . $e->getMessage());
        }
    }
}