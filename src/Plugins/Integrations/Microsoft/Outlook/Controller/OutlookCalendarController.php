<?php

namespace App\Plugins\Integrations\Microsoft\Outlook\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ResponseService;
use App\Plugins\Integrations\Microsoft\Outlook\Service\OutlookCalendarService;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use DateTime;
use Psr\Log\LoggerInterface;

#[Route('/api')]
class OutlookCalendarController extends AbstractController
{
    private ResponseService $responseService;
    private OutlookCalendarService $outlookCalendarService;
    private LoggerInterface $logger;
    
    public function __construct(
        ResponseService $responseService,
        OutlookCalendarService $outlookCalendarService,
        LoggerInterface $logger
    ) {
        $this->responseService = $responseService;
        $this->outlookCalendarService = $outlookCalendarService;
        $this->logger = $logger;
    }
    
    /**
     * Get Outlook OAuth URL
     */
    #[Route('/user/integrations/outlook/auth', name: 'outlook_auth_url#', methods: ['GET'])]
    public function getOutlookAuthUrl(Request $request): JsonResponse
    {
        try {
            $authUrl = $this->outlookCalendarService->getAuthUrl();
            
            return $this->responseService->json(true, 'retrieve', [
                'auth_url' => $authUrl,
                'client_id' => $this->outlookCalendarService->getClientId(),
                'redirect_uri' => $this->outlookCalendarService->getRedirectUri(),
                'tenant_id' => $this->outlookCalendarService->getTenantId()
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error generating auth URL: ' . $e->getMessage());
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

     /**
     * Force reconnection by removing existing integration
     */
    #[Route('/user/integrations/outlook/reconnect', name: 'outlook_reconnect#', methods: ['POST'])]
    public function forceReconnect(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            // Find all existing integrations
            $integrations = $this->entityManager->getRepository('App\Plugins\Integrations\Common\Entity\IntegrationEntity')
                ->findBy([
                    'user' => $user,
                    'provider' => 'outlook_calendar',
                ]);
            
            $count = count($integrations);
            
            // Delete them all to start fresh
            foreach ($integrations as $integration) {
                $this->entityManager->remove($integration);
            }
            
            $this->entityManager->flush();
            
            // Generate a new auth URL
            $authUrl = $this->outlookCalendarService->getAuthUrl();
            
            return $this->responseService->json(true, 'Cleared ' . $count . ' existing integrations. Please reconnect.', [
                'auth_url' => $authUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Failed to reset integration: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Handle Outlook OAuth callback
     */
    #[Route('/user/integrations/outlook/callback', name: 'outlook_auth_callback#', methods: ['POST'])]
    public function handleOutlookCallback(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        if (!isset($data['code'])) {
            return $this->responseService->json(false, 'Code parameter is required', null, 400);
        }
        
        try {
            $integration = $this->outlookCalendarService->handleAuthCallback($user, $data['code']);
            
            return $this->responseService->json(true, 'Outlook Calendar connected successfully', $integration->toArray());
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Sync Outlook Calendar events
     */
    #[Route('/user/integrations/{id}/sync-outlook', name: 'outlook_calendar_sync#', methods: ['POST'])]
    public function syncCalendar(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        // Default to syncing the next 30 days
        $startDate = new DateTime($data['start_date'] ?? 'today');
        $endDate = new DateTime($data['end_date'] ?? '+30 days');
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found', null, 404);
            }
            
            $events = $this->outlookCalendarService->syncEvents($integration, $startDate, $endDate);
            
            return $this->responseService->json(true, 'Events synced successfully', [
                'integration' => $integration->toArray(),
                'events_count' => count($events),
                'sync_range' => [
                    'start' => $startDate->format('Y-m-d H:i:s'),
                    'end' => $endDate->format('Y-m-d H:i:s')
                ]
            ]);
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get events for a date range (from local database)
     */
    #[Route('/user/integrations/outlook/events', name: 'outlook_calendar_events_get#', methods: ['GET'])]
    public function getEvents(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $startDate = $request->query->get('start_date', 'today');
        $endDate = $request->query->get('end_date', '+7 days');
        $autoSync = $request->query->get('sync', 'auto');
        
        try {
            $startDateTime = new DateTime($startDate);
            $endDateTime = new DateTime($endDate);
            
            // Get the user's integration
            $integration = $this->outlookCalendarService->getUserIntegration($user);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found. Please connect your calendar first.', null, 404);
            }
            
            // Add auto-sync logic
            $shouldSync = false;
            if ($autoSync === 'force') {
                $shouldSync = true;
            } else if ($autoSync === 'auto') {
                $shouldSync = !$integration->getLastSynced() || 
                             $integration->getLastSynced() < new DateTime('-30 minutes');
            }
            
            if ($shouldSync) {
                $this->outlookCalendarService->syncEvents($integration, $startDateTime, $endDateTime);
            }
            
            $events = $this->outlookCalendarService->getEventsForDateRange($user, $startDateTime, $endDateTime);
            
            $result = array_map(function($event) {
                return $event->toArray();
            }, $events);
            
            return $this->responseService->json(true, 'retrieve', [
                'events' => $result,
                'metadata' => [
                    'total' => count($result),
                    'start_date' => $startDateTime->format('Y-m-d H:i:s'),
                    'end_date' => $endDateTime->format('Y-m-d H:i:s'),
                    'last_synced' => $integration->getLastSynced() ? 
                        $integration->getLastSynced()->format('Y-m-d H:i:s') : null,
                    'synced_now' => $shouldSync
                ]
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Get calendars from Outlook account
     */
    #[Route('/user/integrations/outlook/calendars', name: 'outlook_calendars_get#', methods: ['GET'])]
    public function getCalendars(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $integrationId = $request->query->get('integration_id');
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found. Please connect your calendar first.', null, 404);
            }
            
            $calendars = $this->outlookCalendarService->getCalendars($integration);
            
            return $this->responseService->json(true, 'retrieve', $calendars);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Create a new event in Outlook Calendar
     */
    #[Route('/user/integrations/{id}/outlook-events', name: 'outlook_calendar_event_create#', methods: ['POST'])]
    public function createEvent(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        // Basic validation
        if (empty($data['title']) || empty($data['start_time']) || empty($data['end_time'])) {
            return $this->responseService->json(false, 'Title, start time, and end time are required', null, 400);
        }
        
        try {
            // Get the user's integration
            $integration = $this->outlookCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found', null, 404);
            }
            
            // Parse dates
            $startTime = new DateTime($data['start_time']);
            $endTime = new DateTime($data['end_time']);
            
            if ($startTime >= $endTime) {
                return $this->responseService->json(false, 'End time must be after start time', null, 400);
            }
            
            // Prepare options
            $options = [
                'description' => $data['description'] ?? null,
                'location' => $data['location'] ?? null,
                'calendar_id' => $data['calendar_id'] ?? null,
                'transparency' => $data['transparency'] ?? 'opaque'
            ];
            
            // Add attendees if provided
            if (!empty($data['attendees']) && is_array($data['attendees'])) {
                $options['attendees'] = $data['attendees'];
            }
            
            // Add reminders if provided
            if (!empty($data['reminders']) && is_array($data['reminders'])) {
                $options['reminders'] = $data['reminders'];
            }
            
            // Create the event
            $event = $this->outlookCalendarService->createCalendarEvent(
                $integration,
                $data['title'],
                $startTime,
                $endTime,
                $options
            );
            
            return $this->responseService->json(true, 'Event created successfully', $event, 201);
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            $this->logger->error('Error creating event: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Delete an event in Outlook Calendar
     */
    #[Route('/user/integrations/{id}/outlook-events/{eventId}', name: 'outlook_calendar_event_delete#', methods: ['DELETE'])]
    public function deleteEvent(int $id, string $eventId, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found', null, 404);
            }
            
            $success = $this->outlookCalendarService->deleteEvent($integration, $eventId);
            
            if ($success) {
                return $this->responseService->json(true, 'Event deleted successfully');
            } else {
                return $this->responseService->json(false, 'Failed to delete event', null, 500);
            }
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }



    /**
     * Test Outlook connection
     */
    #[Route('/user/integrations/outlook/test', name: 'outlook_test#', methods: ['GET'])]
    public function testOutlookConnection(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $integrationId = $request->query->get('integration_id');
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found.', null, 404);
            }
            
            // Get raw token for inspection
            $accessToken = $integration->getAccessToken();
            $tokenParts = explode(".", $accessToken);
            
            // Only proceed if token is properly formatted
            $tokenInfo = ['valid_format' => false];
            if (count($tokenParts) === 3) {
                $tokenInfo['valid_format'] = true;
                
                // Get header
                try {
                    $header = json_decode(base64_decode(str_replace('_', '/', str_replace('-','+', $tokenParts[0]))), true);
                    $tokenInfo['header'] = $header;
                } catch (\Exception $e) {
                    $tokenInfo['header_error'] = $e->getMessage();
                }
                
                // Get payload
                try {
                    $payload = json_decode(base64_decode(str_replace('_', '/', str_replace('-','+', $tokenParts[1]))), true);
                    $tokenInfo['payload'] = $payload;
                    
                    // Extract key information
                    $tokenInfo['app_id'] = $payload['appid'] ?? 'unknown';
                    $tokenInfo['scopes'] = $payload['scp'] ?? 'unknown';
                    $tokenInfo['user_id'] = $payload['oid'] ?? 'unknown';
                    $tokenInfo['expires'] = isset($payload['exp']) ? date('Y-m-d H:i:s', $payload['exp']) : 'unknown';
                    $tokenInfo['issued'] = isset($payload['iat']) ? date('Y-m-d H:i:s', $payload['iat']) : 'unknown';
                } catch (\Exception $e) {
                    $tokenInfo['payload_error'] = $e->getMessage();
                }
            }
            
            // Test calendar endpoints
            $endpoints = [
                'me' => 'https://graph.microsoft.com/v1.0/me',
                'calendars' => 'https://graph.microsoft.com/v1.0/me/calendars',
                'calendar' => 'https://graph.microsoft.com/v1.0/me/calendar',
                'events' => 'https://graph.microsoft.com/v1.0/me/events',
            ];
            
            $testResults = [];
            $client = new \GuzzleHttp\Client(['http_errors' => false]);
            
            foreach ($endpoints as $name => $url) {
                try {
                    $response = $client->get($url, [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                            'Accept' => 'application/json'
                        ]
                    ]);
                    
                    $statusCode = $response->getStatusCode();
                    $responseBody = $response->getBody()->getContents();
                    
                    $testResults[$name] = [
                        'status_code' => $statusCode,
                        'success' => $statusCode >= 200 && $statusCode < 300
                    ];
                    
                    if ($statusCode >= 200 && $statusCode < 300) {
                        $data = json_decode($responseBody, true);
                        $testResults[$name]['response'] = $data;
                    } else {
                        $testResults[$name]['error'] = json_decode($responseBody, true) ?? $responseBody;
                    }
                } catch (\Exception $e) {
                    $testResults[$name] = [
                        'status_code' => 0,
                        'success' => false,
                        'exception' => $e->getMessage()
                    ];
                }
            }
            
            // Check app registration in Azure
            $azureConfig = [
                'client_id' => $this->outlookCalendarService->getClientId(),
                'redirect_uri' => $this->outlookCalendarService->getRedirectUri(),
                'expected_permissions' => [
                    'Calendars.Read',
                    'Calendars.ReadWrite',
                    'User.Read'
                ]
            ];
            
            // Try to refresh the token
            $refreshResults = ['attempted' => false];
            if ($request->query->has('try_refresh') && $request->query->get('try_refresh') === 'true') {
                $refreshResults['attempted'] = true;
                try {
                    $this->outlookCalendarService->refreshToken($integration);
                    $refreshResults['success'] = true;
                    $refreshResults['new_expires'] = $integration->getTokenExpires()->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    $refreshResults['success'] = false;
                    $refreshResults['error'] = $e->getMessage();
                }
            }
            
            return $this->responseService->json(true, 'Advanced test completed', [
                'integration' => [
                    'id' => $integration->getId(),
                    'name' => $integration->getName(),
                    'provider' => $integration->getProvider(),
                    'status' => $integration->getStatus(),
                    'token_expires' => $integration->getTokenExpires() ? $integration->getTokenExpires()->format('Y-m-d H:i:s') : null,
                    'last_synced' => $integration->getLastSynced() ? $integration->getLastSynced()->format('Y-m-d H:i:s') : null,
                    'scopes_stored' => $integration->getScopes(),
                    'has_refresh_token' => !empty($integration->getRefreshToken()),
                ],
                'token_analysis' => $tokenInfo,
                'endpoint_tests' => $testResults,
                'azure_config' => $azureConfig,
                'refresh_attempt' => $refreshResults,
                'reconnect_url' => $this->generateUrl('outlook_reconnect#'),
                'auth_url' => $this->outlookCalendarService->getAuthUrl()
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'Advanced test failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ], 500);
        }
        
    }

    /**
     * Reset Outlook token (force refresh)
     */
    #[Route('/user/integrations/outlook/reset-token', name: 'outlook_reset_token#', methods: ['POST'])]
    public function resetOutlookToken(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        $integrationId = $data['integration_id'] ?? null;
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found.', null, 404);
            }
            
            try {
                // Now this will work since refreshToken is public
                $this->outlookCalendarService->refreshToken($integration);
                
                return $this->responseService->json(true, 'Token refreshed successfully', [
                    'expires' => $integration->getTokenExpires()->format('Y-m-d H:i:s')
                ]);
            } catch (\Exception $e) {
                // If refresh fails, we need to reconnect
                return $this->responseService->json(false, 'Token refresh failed. You need to reconnect Outlook: ' . $e->getMessage(), null, 400);
            }
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }


    /**
     * Get token details for debugging
     */
    #[Route('/user/integrations/outlook/token-details', name: 'outlook_token_details#', methods: ['GET'])]
    public function getTokenDetails(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $integrationId = $request->query->get('integration_id');
        
        try {
            $integration = $this->outlookCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Outlook Calendar integration not found.', null, 404);
            }
            
            // Check token status and expiration
            $tokenExpires = $integration->getTokenExpires();
            $isExpired = $tokenExpires && $tokenExpires < new DateTime();
            
            // Get token information (be careful not to expose the full token)
            return $this->responseService->json(true, 'Token details retrieved', [
                'integration_id' => $integration->getId(),
                'provider' => $integration->getProvider(),
                'token_expires' => $tokenExpires ? $tokenExpires->format('Y-m-d H:i:s') : 'null',
                'is_expired' => $isExpired,
                'has_access_token' => !empty($integration->getAccessToken()),
                'access_token_length' => $integration->getAccessToken() ? strlen($integration->getAccessToken()) : 0,
                'has_refresh_token' => !empty($integration->getRefreshToken()),
                'refresh_token_length' => $integration->getRefreshToken() ? strlen($integration->getRefreshToken()) : 0,
                'scopes' => $integration->getScopes(),
                'last_synced' => $integration->getLastSynced() ? $integration->getLastSynced()->format('Y-m-d H:i:s') : 'null',
                'status' => $integration->getStatus()
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }



   


}