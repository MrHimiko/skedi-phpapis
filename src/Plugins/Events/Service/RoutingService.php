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
    private string $logFile = '/tmp/routing_debug.log';
    
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
    
    private function log($message, $data = null): void
    {
        $entry = date('Y-m-d H:i:s') . " - " . $message;
        if ($data !== null) {
            $entry .= " - " . json_encode($data, JSON_PRETTY_PRINT);
        }
        $entry .= "\n";
        file_put_contents($this->logFile, $entry, FILE_APPEND);
    }
    
    /**
     * Route booking to appropriate assignee
     */
    public function routeBooking(
        EventEntity $event, 
        array $formData, 
        DateTime $requestedTime
    ): ?EventAssigneeEntity {
        $this->log("=== ROUTING START ===");
        $this->log("Event ID: " . $event->getId());
        $this->log("Form Data", $formData);
        
        // If routing not enabled, return null (use default behavior)
        if (!$event->getRoutingEnabled()) {
            $this->log("Routing not enabled, skipping");
            return null;
        }
        
        // Get available assignees
        $assignees = $this->assigneeService->getAssigneesByEvent($event);
        $availableAssignees = $this->filterAvailableAssignees($assignees, $requestedTime);
        
        $this->log("Available assignees count: " . count($availableAssignees));
        
        if (empty($availableAssignees)) {
            $this->log("No assignees available");
            throw new \Exception('No team members available at selected time');
        }
        
        // If only one assignee available, return them
        if (count($availableAssignees) === 1) {
            $this->log("Only one assignee, returning: " . $availableAssignees[0]->getUser()->getName());
            return $availableAssignees[0];
        }
        
        // Try AI routing if instructions exist
        if ($event->getRoutingInstructions()) {
            $this->log("Routing instructions: " . $event->getRoutingInstructions());
            
            try {
                $selectedAssignee = $this->routeWithAI(
                    $event,
                    $formData,
                    $availableAssignees,
                    $requestedTime
                );
                
                if ($selectedAssignee) {
                    $this->log("AI selected: " . $selectedAssignee->getUser()->getName());
                    return $selectedAssignee;
                }
            } catch (\Exception $e) {
                $this->log("AI routing failed: " . $e->getMessage());
                error_log('AI routing failed: ' . $e->getMessage());
            }
        }
        
        // Fallback routing
        $this->log("Using fallback routing");
        return $this->applyFallbackRouting($event, $availableAssignees);
    }
    
    /**
     * Filter assignees by availability
     */
    private function filterAvailableAssignees(array $assignees, DateTime $requestedTime): array
    {
        $available = [];
        
        foreach ($assignees as $assignee) {
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
        
        $this->log("Assignee context", $assigneeContext);
        
        // Build prompt
        $prompt = $this->buildAIPrompt(
            $formData,
            $assigneeContext,
            $event->getRoutingInstructions()
        );
        
        $this->log("AI Prompt", ['prompt' => $prompt]);
        
        // Call OpenAI
        $response = $this->callOpenAI($prompt);
        
        $this->log("AI Response", $response);
        
        // Parse response and find assignee
        if (isset($response['assignee_id'])) {
            foreach ($availableAssignees as $assignee) {
                if ($assignee->getUser()->getId() == $response['assignee_id']) {
                    // Log the routing decision
                    $this->logRoutingDecision(
                        $event,
                        null,
                        $formData,
                        $response,
                        $assignee->getUser()->getId(),
                        'ai'
                    );
                    
                    return $assignee;
                }
            }
            $this->log("Assignee ID from AI not found in available assignees: " . $response['assignee_id']);
        }
        
        return null;
    }
    
    /**
     * Build AI prompt - IMPROVED VERSION
     */
    private function buildAIPrompt(array $formData, array $assignees, string $instructions): string
    {
        // Extract key info from form data
        $customerEmail = $formData['primary_contact']['email'] ?? 'unknown';
        $customerName = $formData['primary_contact']['name'] ?? 'unknown';
        
        $prompt = "You are a meeting routing assistant. Your job is to assign incoming meeting requests to the correct team member.\n\n";
        
        $prompt .= "=== CUSTOMER INFO ===\n";
        $prompt .= "Name: " . $customerName . "\n";
        $prompt .= "Email: " . $customerEmail . "\n";
        
        // Determine email type
        $publicDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'mail.com', 'protonmail.com'];
        $emailDomain = strtolower(substr(strrchr($customerEmail, "@"), 1));
        $isPublicEmail = in_array($emailDomain, $publicDomains);
        
        $prompt .= "Email Domain: " . $emailDomain . "\n";
        $prompt .= "Email Type: " . ($isPublicEmail ? "PUBLIC (personal email like Gmail)" : "PRIVATE (company/business email)") . "\n\n";
        
        // Add any custom fields
        if (!empty($formData['custom_fields'])) {
            $prompt .= "=== ADDITIONAL INFO ===\n";
            foreach ($formData['custom_fields'] as $key => $value) {
                $prompt .= $key . ": " . (is_array($value) ? json_encode($value) : $value) . "\n";
            }
            $prompt .= "\n";
        }
        
        $prompt .= "=== AVAILABLE TEAM MEMBERS ===\n";
        foreach ($assignees as $assignee) {
            $prompt .= "- ID: " . $assignee['id'] . ", Name: " . $assignee['name'] . "\n";
        }
        $prompt .= "\n";
        
        $prompt .= "=== ROUTING RULES ===\n";
        $prompt .= $instructions . "\n\n";
        
        $prompt .= "=== YOUR TASK ===\n";
        $prompt .= "Based on the ROUTING RULES above, select the correct team member for this customer.\n";
        $prompt .= "You MUST return valid JSON with this exact format: {\"assignee_id\": <id>, \"reason\": \"<explanation>\"}\n";
        $prompt .= "Use the ID number from the AVAILABLE TEAM MEMBERS list.\n";
        
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
        
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.1, // Lower temperature for more consistent results
            'max_tokens' => 200
        ];
        
        $this->log("OpenAI Request Payload", $payload);
        
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        $this->log("OpenAI HTTP Code: " . $httpCode);
        $this->log("OpenAI Raw Response", ['response' => $response]);
        
        if ($curlError) {
            $this->log("CURL Error: " . $curlError);
            throw new \Exception('OpenAI API call failed: ' . $curlError);
        }
        
        if ($httpCode !== 200 || !$response) {
            $this->log("OpenAI API error - HTTP " . $httpCode);
            throw new \Exception('OpenAI API call failed with HTTP ' . $httpCode);
        }
        
        $data = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        
        $this->log("AI Content Response: " . $content);
        
        // Clean up the response - remove markdown code blocks if present
        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content);
        $content = preg_replace('/^```\s*/i', '', $content);
        $content = preg_replace('/\s*```$/i', '', $content);
        
        // Parse the JSON response
        $result = json_decode($content, true);
        if (!$result) {
            $this->log("Failed to parse AI response as JSON: " . $content);
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
        
        $this->log("Fallback strategy: " . $strategy);
        
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
     * Log routing decision to database
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
                    (event_id, booking_id, form_data, ai_response, assigned_to, routing_method, created) 
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
            $this->log("Failed to log routing decision: " . $e->getMessage());
        }
    }
}