<?php

namespace App\Plugins\Events\Service;

use App\Plugins\Events\Entity\EventEntity;
use App\Plugins\Events\Entity\EventBookingEntity;
use App\Plugins\Events\Entity\EventAssigneeEntity;
use App\Plugins\Events\Service\EventAssigneeService;
use App\Plugins\Events\Service\EventScheduleService;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;

class RoutingService
{
    private EntityManagerInterface $entityManager;
    private EventAssigneeService $assigneeService;
    private EventScheduleService $scheduleService;
    private string $openaiApiKey;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        EventAssigneeService $assigneeService,
        EventScheduleService $scheduleService,
        string $openaiApiKey
    ) {
        $this->entityManager = $entityManager;
        $this->assigneeService = $assigneeService;
        $this->scheduleService = $scheduleService;
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
        
        // Build prompt
        $prompt = $this->buildAIPrompt(
            $formData,
            $assigneeContext,
            $event->getRoutingInstructions()
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
     * Build AI prompt
     */
    private function buildAIPrompt(array $formData, array $assignees, string $instructions): string
    {
        $prompt = "You are a meeting routing assistant. Based on the following information, select the best assignee.\n\n";
        
        $prompt .= "FORM DATA:\n" . json_encode($formData, JSON_PRETTY_PRINT) . "\n\n";
        
        $prompt .= "AVAILABLE ASSIGNEES:\n" . json_encode($assignees, JSON_PRETTY_PRINT) . "\n\n";
        
        $prompt .= "ROUTING INSTRUCTIONS:\n" . $instructions . "\n\n";
        
        $prompt .= "Return ONLY valid JSON with format: {\"assignee_id\": 123, \"reason\": \"brief explanation\"}";
        
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
                ['role' => 'system', 'content' => $prompt]
            ],
            'temperature' => 0.3,
            'max_tokens' => 150
        ]));
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3); // 3 second timeout
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            throw new \Exception('OpenAI API call failed');
        }
        
        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        // Parse the JSON response
        $result = json_decode($content, true);
        if (!$result) {
            throw new \Exception('Invalid JSON response from AI');
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
        $qb->select('MAX(b.createdAt)')
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
        $sql = "INSERT INTO event_routing_log 
                (event_id, booking_id, form_data, ai_response, assigned_to, routing_method) 
                VALUES (:event_id, :booking_id, :form_data, :ai_response, :assigned_to, :method)";
        
        $stmt = $this->entityManager->getConnection()->prepare($sql);
        $stmt->execute([
            'event_id' => $event->getId(),
            'booking_id' => $booking ? $booking->getId() : null,
            'form_data' => json_encode($formData),
            'ai_response' => json_encode($aiResponse),
            'assigned_to' => $assignedTo,
            'method' => $method
        ]);
    }
}