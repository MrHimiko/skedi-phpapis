<?php

namespace App\Plugins\Integrations\Common\Controller;


use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use App\Service\ResponseService;
use App\Plugins\Integrations\Common\Service\IntegrationService;
use App\Plugins\Integrations\Google\Calendar\Service\GoogleCalendarService;
use App\Plugins\Integrations\Microsoft\Outlook\Service\OutlookCalendarService;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use DateTime;

#[Route('/api')]
class IntegrationsController extends AbstractController
{
    private ResponseService $responseService;
    private GoogleCalendarService $googleCalendarService;
    private IntegrationService $integrationService;
    private IntegrationRepository $IntegrationRepository;
    private OutlookCalendarService $outlookCalendarService;


    public function __construct(
        ResponseService $responseService,
        IntegrationService $integrationService,
        GoogleCalendarService $googleCalendarService,
        OutlookCalendarService $outlookCalendarService,
        IntegrationRepository $IntegrationRepository,
    ) {
        $this->responseService = $responseService;
        $this->googleCalendarService = $googleCalendarService;
        $this->integrationService = $integrationService;
        $this->IntegrationRepository = $IntegrationRepository;
        $this->outlookCalendarService = $outlookCalendarService;
    }
    
    /**
     * Get available integration providers
     */
    #[Route('/user/integrations/providers', name: 'integrations_providers#', methods: ['GET'])]
    public function getProviders(Request $request): JsonResponse
    {
        try {
            $providers = $this->integrationService->getAvailableProviders();
            
            return $this->responseService->json(true, 'retrieve', $providers);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get user integrations
     */
    #[Route('/user/integrations', name: 'user_integrations_get#', methods: ['GET'])]
    public function getUserIntegrations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $provider = $request->query->get('provider');
        
        try {
            $integrations = $this->integrationService->getUserIntegrations($user, $provider);
            
            $result = array_map(function($integration) {
                return $integration->toArray();
            }, $integrations);
            
            return $this->responseService->json(true, 'retrieve', $result);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Delete integration
     */
    #[Route('/user/integrations/{id}', name: 'integration_delete#', methods: ['DELETE'])]
    public function deleteIntegration(string $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // Convert ID to integer
            $integrationId = (int) $id;
            
            $integration = $this->integrationService->getIntegration($integrationId, $user);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Integration not found', null, 404);
            }
            
            // Delete the integration (PostgreSQL will handle cascading deletes)
            $this->integrationService->deleteIntegration($integration);
            
            return $this->responseService->json(true, 'Integration deleted successfully');
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }



    /**
     * Get events from all integrations
     */
    #[Route('/user/integrations/events', name: 'integration_events_get#', methods: ['GET'])]
    public function getEvents(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');


        
        // Get query parameters with defaults
        $startDate = $request->query->get('start_date', 'today');
        $endDate = $request->query->get('end_date', '+90 days');
        $status = $request->query->get('status', 'all'); // all, confirmed, cancelled
        $sync = $request->query->get('sync', 'auto'); // auto, force, none
        $source = $request->query->get('source'); // Optional provider filter
        $timeRange = $request->query->get('time_range'); // Optional: past, upcoming, current
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(100, max(10, (int)$request->query->get('limit', 50)));
        
        try {
            $startDateTime = new DateTime($startDate);
            $endDateTime = new DateTime($endDate);
            
            // Apply time range filter if specified
            if ($timeRange) {
                $now = new DateTime();
                
                switch ($timeRange) {
                    case 'past':
                        $endDateTime = $now;
                        break;
                    case 'upcoming':
                        $startDateTime = $now;
                        break;
                }
            }
            
            // Get active integrations for the user
            $integrations = $this->integrationService->getUserIntegrations($user, $source);


            
            if (empty($integrations)) {

                return $this->responseService->json(true, 'No connected integrations found.', [
                    'events' => [],
                    'pagination' => [
                        'current_page' => $page,
                        'total_pages' => 0,
                        'total_items' => 0,
                        'page_size' => $limit
                    ],
                    'metadata' => [
                        'providers' => []
                    ]
                ]);
            }
            
            $allEvents = [];
            $metadata = [
                'providers' => []
            ];
            
            // Process each integration
            foreach ($integrations as $integration) {
                $provider = $integration->getProvider();


                
                // Initialize provider metadata
                $metadata['providers'][$provider] = [
                    'connected' => true,
                    'last_synced' => $integration->getLastSynced() ? 
                        $integration->getLastSynced()->format('Y-m-d H:i:s') : null,
                    'synced_now' => false,
                    'count' => 0
                ];
                
                // Determine if we should sync
                $shouldSync = false;
                if ($sync === 'force') {
                    $shouldSync = true;
                } else if ($sync === 'auto') {
                    $shouldSync = !$integration->getLastSynced() || 
                                 $integration->getLastSynced() < new DateTime('-30 minutes');
                }
                
                try {
                    switch ($provider) {
                        case 'google_calendar':
                            // Sync if needed
                            if ($shouldSync) {
                                $this->googleCalendarService->syncEvents($integration, $startDateTime, $endDateTime);
                                $metadata['providers'][$provider]['synced_now'] = true;
                            }

                            
                            // Get events from database based on specified filters
                            $events = $this->getGoogleCalendarEvents(
                                $user, 
                                $startDateTime, 
                                $endDateTime, 
                                $status,
                                $timeRange
                            );

                            
                            $allEvents = array_merge($allEvents, $events);
                            $metadata['providers'][$provider]['count'] = count($events);
                            break;
                        case 'outlook_calendar':
                            // Sync if needed
                            if ($shouldSync) {
                                $this->outlookCalendarService->syncEvents($integration, $startDateTime, $endDateTime);
                                $metadata['providers'][$provider]['synced_now'] = true;
                            }
                            
                            // Get events from database based on specified filters
                            $events = $this->getOutlookCalendarEvents(
                                $user, 
                                $startDateTime, 
                                $endDateTime, 
                                $status,
                                $timeRange
                            );
                            
                            $allEvents = array_merge($allEvents, $events);
                            $metadata['providers'][$provider]['count'] = count($events);
                            break;
            
                        // Add cases for other providers as implemented
                    }
                } catch (\Exception $e) {
                    $metadata['providers'][$provider]['error'] = $e->getMessage();
                }
            }


            
            // Sort events by start time
            usort($allEvents, function($a, $b) {
                $aStart = new DateTime($a['start_time']);
                $bStart = new DateTime($b['start_time']);
                return $aStart <=> $bStart;
            });
            
            // Apply pagination
            $totalEvents = count($allEvents);
            $totalPages = ceil($totalEvents / $limit);
            $offset = ($page - 1) * $limit;
            $pagedEvents = array_slice($allEvents, $offset, $limit);
            
            return $this->responseService->json(true, 'Events retrieved successfully.', [
                'events' => $pagedEvents,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_items' => $totalEvents,
                    'page_size' => $limit
                ],
                'metadata' => $metadata
            ]);
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Get events for a specific integration
     */
    #[Route('/user/integrations/{id}/events', name: 'integration_events_by_id_get#', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getEventsByIntegration(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        // Get query parameters with defaults
        $startDate = $request->query->get('start_date', 'today');
        $endDate = $request->query->get('end_date', '+7 days');
        $status = $request->query->get('status', 'all'); // all, confirmed, cancelled
        $sync = $request->query->get('sync', 'auto'); // auto, force, none
        $timeRange = $request->query->get('time_range'); // Optional: past, upcoming, current
        $page = max(1, (int)$request->query->get('page', 1));
        $limit = min(100, max(10, (int)$request->query->get('limit', 50)));
        
        try {
            // Find the integration
            $integration = $this->integrationService->getIntegration($id, $user);
            
            if (!$integration || $integration->getStatus() !== 'active') {
                return $this->responseService->json(false, 'Integration not found', null, 404);
            }
            
            $startDateTime = new DateTime($startDate);
            $endDateTime = new DateTime($endDate);
            
            // Apply time range filter if specified
            if ($timeRange) {
                $now = new DateTime();
                
                switch ($timeRange) {
                    case 'past':
                        $endDateTime = $now;
                        break;
                    case 'upcoming':
                        $startDateTime = $now;
                        break;
                }
            }
            
            $provider = $integration->getProvider();
            $events = [];
            $synced = false;
            
            // Determine if we should sync
            $shouldSync = false;
            if ($sync === 'force') {
                $shouldSync = true;
            } else if ($sync === 'auto') {
                $shouldSync = !$integration->getLastSynced() || 
                             $integration->getLastSynced() < new DateTime('-30 minutes');
            }
            
            switch ($provider) {
                case 'google_calendar':
                    // Sync if needed
                    if ($shouldSync) {
                        $this->googleCalendarService->syncEvents($integration, $startDateTime, $endDateTime);
                        $synced = true;
                    }
                    
                    // Get events from database
                    $events = $this->getGoogleCalendarEvents(
                        $user, 
                        $startDateTime, 
                        $endDateTime, 
                        $status,
                        $timeRange
                    );
                    break;
                    
                default:
                    return $this->responseService->json(false, 'Unsupported integration type', null, 400);
            }
            
            // Sort events by start time
            usort($events, function($a, $b) {
                $aStart = new DateTime($a['start_time']);
                $bStart = new DateTime($b['start_time']);
                return $aStart <=> $bStart;
            });
            
            // Apply pagination
            $totalEvents = count($events);
            $totalPages = ceil($totalEvents / $limit);
            $offset = ($page - 1) * $limit;
            $pagedEvents = array_slice($events, $offset, $limit);
            
            return $this->responseService->json(true, 'Events retrieved successfully.', [
                'events' => $pagedEvents,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_items' => $totalEvents,
                    'page_size' => $limit
                ],
                'metadata' => [
                    'provider' => $provider,
                    'last_synced' => $integration->getLastSynced() ? 
                        $integration->getLastSynced()->format('Y-m-d H:i:s') : null,
                    'synced_now' => $synced
                ]
            ]);
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Convert Google Calendar events to a standardized format
     */
    private function getGoogleCalendarEvents(
        $user, 
        DateTime $startDateTime, 
        DateTime $endDateTime, 
        string $status = 'all',
        ?string $timeRange = null
    ): array {

        // Get events from database
        $googleEvents = $this->googleCalendarService->getEventsForDateRange($user, $startDateTime, $endDateTime);


        $now = new DateTime();
        $standardizedEvents = [];
        
        foreach ($googleEvents as $event) {
            // Skip based on status filter
            if ($status !== 'all') {
                if ($status === 'confirmed' && $event->getStatus() !== 'confirmed') {
                    continue;
                }
                if ($status === 'cancelled' && $event->getStatus() !== 'cancelled') {
                    continue;
                }
            }
            
            // Apply time range filter for 'current' events
            if ($timeRange === 'current') {
                $eventStart = $event->getStartTime();
                $eventEnd = $event->getEndTime();
                
                // Only include events that are currently happening
                if (!($eventStart <= $now && $eventEnd >= $now)) {
                    continue;
                }
            }
            
            // Add to results
            $standardizedEvents[] = [
                'id' => $event->getId(),
                'source' => 'google_calendar',
                'source_id' => $event->getGoogleEventId(),
                'title' => $event->getTitle(),
                'description' => $event->getDescription(),
                'start_time' => $event->getStartTime()->format('Y-m-d H:i:s'),
                'end_time' => $event->getEndTime()->format('Y-m-d H:i:s'),
                'location' => $event->getLocation(),
                'is_all_day' => $event->isAllDay(),
                'status' => $event->getStatus(),
                'calendar_id' => $event->getCalendarId(),
                'calendar_name' => $event->getCalendarName(),
                'is_busy' => $event->isBusy(),
                'html_link' => $event->getHtmlLink(),
                'created' => $event->getCreated()->format('Y-m-d H:i:s'),
                'updated' => $event->getUpdated()->format('Y-m-d H:i:s')
            ];
        }
        
        return $standardizedEvents;
    }



    private function getOutlookCalendarEvents(
        $user, 
        DateTime $startDateTime, 
        DateTime $endDateTime, 
        string $status = 'all',
        ?string $timeRange = null
    ): array {
        // Get events from database
        $outlookEvents = $this->outlookCalendarService->getEventsForDateRange($user, $startDateTime, $endDateTime);
        
        $now = new DateTime();
        $standardizedEvents = [];
        
        foreach ($outlookEvents as $event) {
            // Skip based on status filter
            if ($status !== 'all') {
                if ($status === 'confirmed' && $event->getStatus() !== 'confirmed') {
                    continue;
                }
                if ($status === 'cancelled' && $event->getStatus() !== 'cancelled') {
                    continue;
                }
            }
            
            // Apply time range filter for 'current' events
            if ($timeRange === 'current') {
                $eventStart = $event->getStartTime();
                $eventEnd = $event->getEndTime();
                
                // Only include events that are currently happening
                if (!($eventStart <= $now && $eventEnd >= $now)) {
                    continue;
                }
            }
            
            // Add to results
            $standardizedEvents[] = [
                'id' => $event->getId(),
                'source' => 'outlook_calendar',
                'source_id' => $event->getOutlookEventId(),
                'title' => $event->getTitle(),
                'description' => $event->getDescription(),
                'start_time' => $event->getStartTime()->format('Y-m-d H:i:s'),
                'end_time' => $event->getEndTime()->format('Y-m-d H:i:s'),
                'location' => $event->getLocation(),
                'is_all_day' => $event->isAllDay(),
                'status' => $event->getStatus(),
                'calendar_id' => $event->getCalendarId(),
                'calendar_name' => $event->getCalendarName(),
                'is_busy' => $event->isBusy(),
                'web_link' => $event->getWebLink(),
                'created' => $event->getCreated()->format('Y-m-d H:i:s'),
                'updated' => $event->getUpdated()->format('Y-m-d H:i:s')
            ];
        }
        
        return $standardizedEvents;
    }



    #[Route('/user/integrations/test-save', name: 'integration_test_save#', methods: ['GET'])]
    public function testSave(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // Get Google Calendar integration
            $integrations = $this->IntegrationRepository->findBy([
                'user' => $user,
                'provider' => 'google_calendar',
                'status' => 'active'
            ]);
            
            if (empty($integrations)) {
                return $this->responseService->json(false, 'No Google Calendar integration found');
            }
            
            $integration = $integrations[0];
            
            // Test saving an event
            $result = $this->googleCalendarService->testSaveEvent($integration);
            
            return $this->responseService->json($result['save_success'], 'Test save result', $result);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), [
                'error' => $e->getMessage(),
                'trace' => explode("\n", $e->getTraceAsString())
            ], 500);
        }
        }

        

}