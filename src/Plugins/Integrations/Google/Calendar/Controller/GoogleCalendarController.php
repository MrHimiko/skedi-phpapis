<?php
// src/Plugins/Integrations/Controller/GoogleCalendarController.php

namespace App\Plugins\Integrations\Google\Calendar\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\ResponseService;
use App\Plugins\Integrations\Google\Calendar\Service\GoogleCalendarService;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Common\Exception\IntegrationException; 
use Doctrine\ORM\EntityManagerInterface;
use DateTime;

#[Route('/api')]
class GoogleCalendarController extends AbstractController
{
    private ResponseService $responseService;
    private GoogleCalendarService $googleCalendarService;
    private EntityManagerInterface $entityManager;
    
    public function __construct(
        ResponseService $responseService,
        GoogleCalendarService $googleCalendarService,
        EntityManagerInterface $entityManager
    ) {
        $this->responseService = $responseService;
        $this->googleCalendarService = $googleCalendarService;
        $this->entityManager = $entityManager;
    }
    
    /**
     * Get Google OAuth URL
     */
    #[Route('/user/integrations/google/auth', name: 'google_auth_url#', methods: ['GET'])]
    public function getGoogleAuthUrl(Request $request): JsonResponse
    {
        try {
            // Use the service method that has approval_prompt=force
            $authUrl = $this->googleCalendarService->getAuthUrl();
            
            return $this->responseService->json(true, 'retrieve', [
                'auth_url' => $authUrl
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Handle Google OAuth callback
     */
    #[Route('/user/integrations/google/callback', name: 'google_auth_callback#', methods: ['POST'])]
    public function handleGoogleCallback(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        if (!isset($data['code'])) {
            return $this->responseService->json(false, 'Code parameter is required', null, 400);
        }
        
        try {
            // Use the new handleCallback method that ensures refresh token
            $integration = $this->googleCalendarService->handleAuthCallback($user, $data['code']);
            
            return $this->responseService->json(true, 'Google Calendar connected successfully', $integration->toArray());
        } catch (IntegrationException $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 400);
        } catch (\Exception $e) {
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }
    
    /**
     * Sync Google Calendar events
     */
    #[Route('/user/integrations/{id}/sync', name: 'google_calendar_sync#', methods: ['POST'])]
    public function syncCalendar(int $id, Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $data = $request->attributes->get('data');
        
        // Default to syncing the next 30 days
        $startDate = new DateTime($data['start_date'] ?? 'today');
        $endDate = new DateTime($data['end_date'] ?? '+60 days');
        
        try {
            $integration = $this->googleCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google Calendar integration not found', null, 404);
            }
            
            $events = $this->googleCalendarService->syncEvents($integration, $startDate, $endDate);
            
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
     * Get calendars from Google account
     */
    #[Route('/user/integrations/google/calendars', name: 'google_calendars_get#', methods: ['GET'])]
    public function getCalendars(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $integrationId = $request->query->get('integration_id');
        
        try {
            $integration = $this->googleCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google Calendar integration not found. Please connect your calendar first.', null, 404);
            }
            
            $calendars = $this->googleCalendarService->getCalendars($integration);
            
            return $this->responseService->json(true, 'retrieve', $calendars);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Create a new event in Google Calendar
     */
    #[Route('/user/integrations/{id}/events', name: 'google_calendar_event_create#', methods: ['POST'])]
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
            $integration = $this->googleCalendarService->getUserIntegration($user, $id);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google Calendar integration not found', null, 404);
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
                'calendar_id' => $data['calendar_id'] ?? 'primary',
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
            $event = $this->googleCalendarService->createCalendarEvent(
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
            return $this->responseService->json(false, 'An error occurred: ' . $e->getMessage(), null, 500);
        }
    }

    /**
     * Test token refresh functionality
     * This endpoint allows you to test if the refresh token works correctly
     */
    #[Route('/user/integrations/google/test-refresh', name: 'google_test_refresh#', methods: ['POST'])]
    public function testTokenRefresh(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $integrationId = $request->attributes->get('data')['integration_id'] ?? null;
        
        try {
            $integration = $this->googleCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google Calendar integration not found', null, 404);
            }
            
            // Get current token info
            $currentTokenInfo = [
                'has_access_token' => !empty($integration->getAccessToken()),
                'has_refresh_token' => !empty($integration->getRefreshToken()),
                'token_expires' => $integration->getTokenExpires() ? $integration->getTokenExpires()->format('Y-m-d H:i:s') : null,
                'is_expired' => $integration->getTokenExpires() ? $integration->getTokenExpires() < new DateTime() : true
            ];
            
            // Force token expiration to test refresh
            $originalExpiry = $integration->getTokenExpires();
            $integration->setTokenExpires(new DateTime('-1 hour'));
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // Try to refresh the token
            try {
                $client = $this->googleCalendarService->getGoogleClient($integration);
                
                // This will trigger a refresh since we forced expiration
                if ($client->isAccessTokenExpired()) {
                    $accessToken = $client->fetchAccessTokenWithRefreshToken($integration->getRefreshToken());
                    
                    if (isset($accessToken['error'])) {
                        // Restore original expiry on failure
                        $integration->setTokenExpires($originalExpiry);
                        $this->entityManager->persist($integration);
                        $this->entityManager->flush();
                        
                        return $this->responseService->json(false, 'Refresh token test failed', [
                            'error' => $accessToken['error'],
                            'error_description' => $accessToken['error_description'] ?? 'No description',
                            'current_token_info' => $currentTokenInfo,
                            'refresh_token_exists' => !empty($integration->getRefreshToken()),
                            'integration_created' => $integration->getCreated()->format('Y-m-d H:i:s'),
                            'last_synced' => $integration->getLastSynced() ? $integration->getLastSynced()->format('Y-m-d H:i:s') : 'never'
                        ], 400);
                    }
                    
                    // Update the integration with new token
                    $expiresIn = $accessToken['expires_in'] ?? 3600;
                    $expiresAt = new DateTime();
                    $expiresAt->modify("+{$expiresIn} seconds");
                    
                    $integration->setAccessToken($accessToken['access_token']);
                    $integration->setTokenExpires($expiresAt);
                    
                    // Only update refresh token if a new one was provided
                    if (isset($accessToken['refresh_token'])) {
                        $integration->setRefreshToken($accessToken['refresh_token']);
                    }
                    
                    $this->entityManager->persist($integration);
                    $this->entityManager->flush();
                    
                    return $this->responseService->json(true, 'Token refresh successful!', [
                        'message' => 'Your Google Calendar refresh token is working correctly',
                        'new_token_expires' => $expiresAt->format('Y-m-d H:i:s'),
                        'refresh_token_updated' => isset($accessToken['refresh_token']),
                        'previous_token_info' => $currentTokenInfo,
                        'test_details' => [
                            'forced_expiration' => true,
                            'refresh_worked' => true,
                            'integration_will_continue_working' => true
                        ]
                    ]);
                } else {
                    return $this->responseService->json(true, 'Token is not expired yet', [
                        'current_token_info' => $currentTokenInfo,
                        'message' => 'Token is still valid, but refresh token exists'
                    ]);
                }
            } catch (\Exception $e) {
                // Restore original expiry on exception
                $integration->setTokenExpires($originalExpiry);
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
                
                return $this->responseService->json(false, 'Token refresh test failed with exception', [
                    'error' => $e->getMessage(),
                    'current_token_info' => $currentTokenInfo,
                    'trace' => $e->getTraceAsString()
                ], 500);
            }
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    /**
     * Get detailed token information for debugging
     */
    #[Route('/user/integrations/google/token-info', name: 'google_token_info#', methods: ['GET'])]
    public function getTokenInfo(Request $request): JsonResponse
    {
        $user = $request->attributes->get('user');
        $integrationId = $request->query->get('integration_id');
        
        try {
            $integration = $this->googleCalendarService->getUserIntegration($user, $integrationId);
            
            if (!$integration) {
                return $this->responseService->json(false, 'Google Calendar integration not found', null, 404);
            }
            
            // Decode the current access token to check its properties
            $client = $this->googleCalendarService->getGoogleClient($integration);
            $accessToken = $client->getAccessToken();
            
            return $this->responseService->json(true, 'Token information retrieved', [
                'integration_id' => $integration->getId(),
                'provider' => $integration->getProvider(),
                'status' => $integration->getStatus(),
                'created' => $integration->getCreated()->format('Y-m-d H:i:s'),
                'last_synced' => $integration->getLastSynced() ? $integration->getLastSynced()->format('Y-m-d H:i:s') : 'never',
                'token_info' => [
                    'has_access_token' => !empty($integration->getAccessToken()),
                    'has_refresh_token' => !empty($integration->getRefreshToken()),
                    'token_expires' => $integration->getTokenExpires() ? $integration->getTokenExpires()->format('Y-m-d H:i:s') : null,
                    'is_expired' => $integration->getTokenExpires() ? $integration->getTokenExpires() < new DateTime() : true,
                    'expires_in_seconds' => $integration->getTokenExpires() ? $integration->getTokenExpires()->getTimestamp() - time() : null
                ],
                'client_token_state' => [
                    'is_expired' => $client->isAccessTokenExpired(),
                    'access_token_set' => !empty($accessToken),
                    'token_type' => $accessToken['token_type'] ?? null,
                    'created_timestamp' => $accessToken['created'] ?? null
                ],
                'recommendations' => $this->getTokenRecommendations($integration)
            ]);
        } catch (\Exception $e) {
            return $this->responseService->json(false, $e->getMessage(), null, 500);
        }
    }

    private function getTokenRecommendations(IntegrationEntity $integration): array
    {
        $recommendations = [];
        
        if (!$integration->getRefreshToken()) {
            $recommendations[] = 'No refresh token found - reconnection required';
        }
        
        if ($integration->getStatus() === 'expired') {
            $recommendations[] = 'Integration marked as expired - reconnection required';
        }
        
        if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
            $recommendations[] = 'Access token expired - will attempt refresh on next use';
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Integration appears healthy';
        }
        
        return $recommendations;
    }
}