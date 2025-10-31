<?php
// src/Plugins/Integrations/Google/Meet/Service/GoogleMeetService.php

namespace App\Plugins\Integrations\Google\Meet\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Google\Meet\Repository\GoogleMeetEventRepository;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Google\Meet\Entity\GoogleMeetEventEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use App\Service\CrudManager;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use DateTime;
use DateTimeInterface;

// Google API imports
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Oauth2;

class GoogleMeetService
{
    private EntityManagerInterface $entityManager;
    private IntegrationRepository $integrationRepository;
    private GoogleMeetEventRepository $googleMeetEventRepository;
    private CrudManager $crudManager;
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        IntegrationRepository $integrationRepository,
        GoogleMeetEventRepository $googleMeetEventRepository,
        CrudManager $crudManager,
        ParameterBagInterface $parameterBag = null
    ) {
        $this->entityManager = $entityManager;
        $this->integrationRepository = $integrationRepository;
        $this->googleMeetEventRepository = $googleMeetEventRepository;
        $this->crudManager = $crudManager;
        
        // Use same credentials as Calendar service for consistency
        $this->clientId = $_ENV['GOOGLE_CLIENT_ID'];
        $this->clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'];
        $this->redirectUri = $_ENV['GOOGLE_REDIRECT_URI'];

    }

    /**
     * Create properly configured Google Client with CORRECT OAuth parameters
     * This mirrors the Calendar service configuration exactly
     */
    private function createBaseGoogleClient(): GoogleClient
    {
        $client = new GoogleClient();
        
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        
        $client->setScopes([
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/meetings.space.created',
            'https://www.googleapis.com/auth/meetings.space.readonly',
            'https://www.googleapis.com/auth/userinfo.email'
        ]);
        
        // CRITICAL: These parameters ensure we ALWAYS get refresh tokens
        $client->setAccessType('offline');        // Required for refresh tokens
        $client->setPrompt('consent');           // Forces consent screen = guaranteed refresh token
        $client->setIncludeGrantedScopes(true); // Enables incremental authorization
        
        return $client;
    }

    /**
     * Get OAuth URL with GUARANTEED refresh token parameters
     */
    public function getAuthUrl(): string
    {
        $client = $this->createBaseGoogleClient();
        
        $authUrl = $client->createAuthUrl();
        
        // Debug logging to verify correct parameters
        error_log('Google Meet Auth URL: ' . $authUrl);
        
        // Validate critical parameters are present
        if (!str_contains($authUrl, 'access_type=offline')) {
            throw new IntegrationException('Critical error: Missing access_type=offline parameter');
        }
        
        if (!str_contains($authUrl, 'prompt=consent')) {
            throw new IntegrationException('Critical error: Missing prompt=consent parameter');
        }
        
        return $authUrl;
    }

    /**
     * Get Google Client instance with token handling and auto-refresh
     */
    public function getGoogleClient(?IntegrationEntity $integration = null): GoogleClient
    {
        $client = $this->createBaseGoogleClient();
        
        if ($integration && $integration->getAccessToken()) {
            // Prepare token data in the format Google Client expects
            $tokenData = [
                'access_token' => $integration->getAccessToken(),
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'created' => time()
            ];
            
            if ($integration->getRefreshToken()) {
                $tokenData['refresh_token'] = $integration->getRefreshToken();
            }
            
            if ($integration->getTokenExpires()) {
                $expiresIn = $integration->getTokenExpires()->getTimestamp() - time();
                $tokenData['expires_in'] = max(1, $expiresIn);
                $tokenData['created'] = $integration->getTokenExpires()->getTimestamp() - 3600;
            }
            
            $client->setAccessToken($tokenData);
            
            // Auto-refresh if needed
            if ($client->isAccessTokenExpired() && $integration->getRefreshToken()) {
                try {
                    $this->refreshTokenWithRetry($integration);
                    // Reload the token data after refresh
                    $tokenData['access_token'] = $integration->getAccessToken();
                    if ($integration->getTokenExpires()) {
                        $expiresIn = $integration->getTokenExpires()->getTimestamp() - time();
                        $tokenData['expires_in'] = max(1, $expiresIn);
                        $tokenData['created'] = $integration->getTokenExpires()->getTimestamp() - 3600;
                    }
                    $client->setAccessToken($tokenData);
                } catch (\Exception $e) {
                    // Mark integration as requiring re-auth
                    $integration->setStatus('expired');
                    $this->entityManager->persist($integration);
                    $this->entityManager->flush();
                    
                    throw new IntegrationException(
                        'Google Meet access expired. Please reconnect your account. Error: ' . $e->getMessage()
                    );
                }
            }
        }
        
        return $client;
    }

    /**
     * Handle OAuth callback and exchange code for tokens
     * This follows EXACTLY the same pattern as GoogleCalendarService
     */
    public function handleAuthCallback(UserEntity $user, string $code): IntegrationEntity
    {
        try {
            // Create a new Google client for this authentication flow
            $client = $this->createBaseGoogleClient();
            
            // Exchange the authorization code for an access token
            try {
                $accessToken = $client->fetchAccessTokenWithAuthCode($code);
                
                if (isset($accessToken['error'])) {
                    error_log('Google Meet token exchange error: ' . json_encode($accessToken));
                    throw new IntegrationException('Failed to get access token: ' . 
                        ($accessToken['error_description'] ?? $accessToken['error']));
                }
                
                // CRITICAL: Verify we got a refresh token
                if (!isset($accessToken['refresh_token'])) {
                    error_log('No refresh token received for Google Meet! Response: ' . json_encode($accessToken));
                    error_log('Possible causes:');
                    error_log('1. User previously authorized (should be fixed by prompt=consent)');
                    error_log('2. Application in testing mode (7-day expiration)');
                    error_log('3. Missing access_type=offline (should be fixed)');
                    
                    throw new IntegrationException(
                        'No refresh token received. This should not happen with the new configuration. ' .
                        'Please check if your Google Cloud Console app is in "Testing" mode, ' .
                        'which limits refresh tokens to 7 days.'
                    );
                }
                
                error_log('Successfully received refresh token for Google Meet');
                
            } catch (\Exception $e) {
                if ($e instanceof IntegrationException) {
                    throw $e;
                }
                throw new IntegrationException('Token exchange failed: ' . $e->getMessage());
            }
            
            // Create expiration date
            $expiresIn = isset($accessToken['expires_in']) ? $accessToken['expires_in'] : 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            // Try to get Google account email
            $googleEmail = null;
            $googleUserId = null;
            
            try {
                // Create a new client just for this operation
                $userClient = $this->createBaseGoogleClient();
                $userClient->setAccessToken($accessToken);
                
                // Call the userinfo API
                $oauth2 = new \Google\Service\Oauth2($userClient);
                $userInfo = $oauth2->userinfo->get();
                
                // Store the email and user ID
                $googleEmail = $userInfo->getEmail();
                $googleUserId = $userInfo->getId();
            } catch (\Exception $e) {
                error_log('Failed to get Google Meet user info: ' . $e->getMessage());
                // Continue without email/user info
            }
            
            // Use Google email if available, otherwise fall back to user's system email
            $integrationName = 'Google Meet';
            if ($googleEmail) {
                $integrationName .= ' (' . $googleEmail . ')';
            } else {
                $integrationName .= ' (' . $user->getEmail() . ')';
            }
            
            // Use Google user ID if available, otherwise generate one
            $externalId = $googleUserId ?? 'google_meet_' . uniqid();
            
            // Check if this user already has a Google Meet integration
            $existingIntegration = $this->integrationRepository->findOneBy([
                'user' => $user,
                'provider' => 'google_meet',
                'status' => 'active'
            ]);
            
            $integration = null;
            
            if ($existingIntegration) {
                // Update existing integration
                $existingIntegration->setAccessToken($accessToken['access_token']);
                $existingIntegration->setRefreshToken($accessToken['refresh_token']);
                $existingIntegration->setTokenExpires($expiresAt);
                
                // Update name and external ID if we got new info
                if ($googleEmail) {
                    $existingIntegration->setName($integrationName);
                }
                
                if ($googleUserId) {
                    $existingIntegration->setExternalId($externalId);
                }
                
                // Update config with Google email
                $config = $existingIntegration->getConfig() ?? [];
                if ($googleEmail) {
                    $config['google_email'] = $googleEmail;
                }
                $existingIntegration->setConfig($config);
                
                // Ensure it's marked as active
                $existingIntegration->setStatus('active');
                
                $this->entityManager->persist($existingIntegration);
                $this->entityManager->flush();
                
                $integration = $existingIntegration;
            } else {
                // Create new integration
                $integration = new IntegrationEntity();
                $integration->setUser($user);
                $integration->setProvider('google_meet');
                $integration->setName($integrationName);
                $integration->setExternalId($externalId);
                $integration->setAccessToken($accessToken['access_token']);
                $integration->setRefreshToken($accessToken['refresh_token']);
                $integration->setTokenExpires($expiresAt);
                $integration->setScopes(implode(',', [
                    'https://www.googleapis.com/auth/calendar',
                    'https://www.googleapis.com/auth/meetings.space.created',
                    'https://www.googleapis.com/auth/meetings.space.readonly',
                    'https://www.googleapis.com/auth/userinfo.email'
                ]));
                
                // Store Google email in the config
                $config = [];
                if ($googleEmail) {
                    $config['google_email'] = $googleEmail;
                }
                $integration->setConfig($config);
                $integration->setStatus('active');
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
            }
            
            return $integration;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to authenticate with Google: ' . $e->getMessage());
        }
    }

    /**
     * Get user's Google Meet integration
     */
    public function getUserIntegration(UserEntity $user, ?int $integrationId = null): ?IntegrationEntity
    {
        if ($integrationId) {
            $integration = $this->integrationRepository->find($integrationId);
            if ($integration && $integration->getUser()->getId() === $user->getId() && 
                $integration->getProvider() === 'google_meet' && 
                $integration->getStatus() === 'active') {
                return $integration;
            }
            return null;
        }
        
        // Get the most recently created active integration
        return $this->integrationRepository->findOneBy(
            [
                'user' => $user,
                'provider' => 'google_meet',
                'status' => 'active'
            ],
            ['created' => 'DESC']
        );
    }
    
    /**
     * Find a Meet event by booking ID
     */
    public function getMeetLinkForBooking(int $bookingId): ?GoogleMeetEventEntity
    {
        try {
            $filters = [
                [
                    'field' => 'bookingId',
                    'operator' => 'equals',
                    'value' => $bookingId
                ]
            ];
            
            $results = $this->crudManager->findMany(
                GoogleMeetEventEntity::class,
                $filters,
                1,
                1,
                []
            );
            
            return !empty($results) ? $results[0] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Robust token refresh with retry logic (same as Calendar service)
     */
    private function refreshTokenWithRetry(IntegrationEntity $integration, int $maxRetries = 3): array
    {
        if (!$integration->getRefreshToken()) {
            throw new IntegrationException('No refresh token available for refresh');
        }
        
        $client = $this->createBaseGoogleClient();
        
        $attempt = 0;
        $baseDelay = 1000; // 1 second
        
        while ($attempt < $maxRetries) {
            try {
                error_log("Attempting to refresh Google Meet token (attempt " . ($attempt + 1) . ")");
                
                $accessToken = $client->fetchAccessTokenWithRefreshToken($integration->getRefreshToken());
                
                if (isset($accessToken['error'])) {
                    error_log('Google Meet token refresh error: ' . json_encode($accessToken));
                    
                    // Handle specific error types
                    if ($accessToken['error'] === 'invalid_grant') {
                        throw new IntegrationException(
                            'Refresh token is invalid or expired. ' .
                            'This may happen if the app is in testing mode (7-day limit) or ' .
                            'if the user revoked access. User must reconnect.'
                        );
                    }
                    
                    if ($accessToken['error'] === 'invalid_request') {
                        throw new IntegrationException(
                            'Invalid refresh token request. User must reconnect.'
                        );
                    }
                    
                    // For rate limiting or temporary errors, retry
                    if (in_array($accessToken['error'], ['temporarily_unavailable', 'internal_failure'])) {
                        $attempt++;
                        if ($attempt < $maxRetries) {
                            $delay = $baseDelay * pow(2, $attempt); // Exponential backoff
                            error_log("Temporary error, retrying in {$delay}ms...");
                            usleep($delay * 1000);
                            continue;
                        }
                    }
                    
                    throw new IntegrationException('Token refresh failed: ' . $accessToken['error']);
                }
                
                // Update the integration with new token data
                $expiresIn = $accessToken['expires_in'] ?? 3600;
                $expiresAt = new DateTime();
                $expiresAt->modify("+{$expiresIn} seconds");
                
                $integration->setAccessToken($accessToken['access_token']);
                $integration->setTokenExpires($expiresAt);
                
                // Important: Only update refresh token if a new one was provided
                if (isset($accessToken['refresh_token'])) {
                    $integration->setRefreshToken($accessToken['refresh_token']);
                    error_log('New refresh token received and saved for Google Meet');
                }
                
                // Ensure integration is marked as active
                $integration->setStatus('active');
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
                
                error_log('Successfully refreshed Google Meet token');
                
                return $accessToken;
                
            } catch (IntegrationException $e) {
                throw $e; // Don't retry integration exceptions
            } catch (\Exception $e) {
                $attempt++;
                error_log("Google Meet token refresh attempt {$attempt} failed: " . $e->getMessage());
                
                if ($attempt >= $maxRetries) {
                    throw new IntegrationException('Token refresh failed after ' . $maxRetries . ' attempts: ' . $e->getMessage());
                }
                
                // Exponential backoff for retries
                $delay = $baseDelay * pow(2, $attempt);
                usleep($delay * 1000);
            }
        }
        
        throw new IntegrationException('Token refresh failed after maximum retry attempts');
    }
    
    /**
     * Proactive token refresh - call this before token expires
     */
    private function refreshTokenIfNeeded(IntegrationEntity $integration): void
    {
        // Refresh if token expires within 10 minutes
        $refreshThreshold = new DateTime('+10 minutes');
        
        if (!$integration->getTokenExpires() || 
            $integration->getTokenExpires() <= $refreshThreshold) {
            
            if (!$integration->getRefreshToken()) {
                throw new IntegrationException(
                    'Access token expired and no refresh token available. User must reconnect.'
                );
            }
            
            try {
                $this->refreshTokenWithRetry($integration);
                error_log('Proactively refreshed token for Google Meet integration ID: ' . $integration->getId());
            } catch (\Exception $e) {
                error_log('Proactive Google Meet token refresh failed: ' . $e->getMessage());
                throw $e;
            }
        }
    }
    
    /**
     * Create a Google Meet link with improved token handling
     */
    public function createMeetLink(
        IntegrationEntity $integration,
        string $title,
        DateTimeInterface $startTime,
        DateTimeInterface $endTime,
        ?int $eventId = null,
        ?int $bookingId = null,
        array $options = []
    ): GoogleMeetEventEntity {
        try {
            // Proactively refresh token before making API calls
            $this->refreshTokenIfNeeded($integration);

            $client = $this->getGoogleClient($integration);
            $service = new GoogleCalendar($client);
            
            // Create a new event with conference data to generate Meet link
            $event = new \Google\Service\Calendar\Event();
            $event->setSummary('Skedi: ' . $title);
            
            // Set start and end times
            $startDateTime = clone $startTime;
            $endDateTime = clone $endTime;
            
            $start = new \Google\Service\Calendar\EventDateTime();
            $start->setDateTime($startDateTime->format('c'));
            $event->setStart($start);
            
            $end = new \Google\Service\Calendar\EventDateTime();
            $end->setDateTime($endDateTime->format('c'));
            $event->setEnd($end);
            
            // Add conference data request
            $conferenceData = new \Google\Service\Calendar\ConferenceData();
            $conferenceRequest = new \Google\Service\Calendar\CreateConferenceRequest();
            
            // Set optional conference parameters if provided
            $conferenceDataParams = [];
            
            // Check for available settings
            if (isset($options['is_guest_allowed']) && $options['is_guest_allowed'] === false) {
                $conferenceDataParams[] = [
                    'key' => 'allowExternalUsers',
                    'value' => 'false'
                ];
            }
            
            // Add recording settings if specified
            if (isset($options['enable_recording']) && $options['enable_recording'] === true) {
                $conferenceDataParams[] = [
                    'key' => 'autoRecord',
                    'value' => 'true'
                ];
            }
            
            // Set conference data parameters if we have any
            if (!empty($conferenceDataParams)) {
                $parameters = [];
                foreach ($conferenceDataParams as $param) {
                    $parameter = new \Google\Service\Calendar\ConferenceParameter();
                    $parameter->setKey($param['key']);
                    $parameter->setValue($param['value']);
                    $parameters[] = $parameter;
                }
                
                $conferenceRequest->setRequestId(uniqid('meet_'));
                $conferenceRequest->setConferenceSolutionKey(
                    new \Google\Service\Calendar\ConferenceSolutionKey(['type' => 'hangoutsMeet'])
                );
                
                if (!empty($parameters)) {
                    $conferenceRequest->setParameters($parameters);
                }
                
                $conferenceData->setCreateRequest($conferenceRequest);
                $event->setConferenceData($conferenceData);
            } else {
                // Simple conference request without custom parameters
                $conferenceRequest->setRequestId(uniqid('meet_'));
                $conferenceRequest->setConferenceSolutionKey(
                    new \Google\Service\Calendar\ConferenceSolutionKey(['type' => 'hangoutsMeet'])
                );
                
                $conferenceData->setCreateRequest($conferenceRequest);
                $event->setConferenceData($conferenceData);
            }
            
            // Set extended properties to mark this as our own event with enhanced metadata
            $extendedProperties = new \Google\Service\Calendar\EventExtendedProperties();
            $privateProperties = [
                'skedi_event' => 'true',
                'skedi_meet' => 'true',
                'skedi_created_at' => (new DateTime())->format('c'),
                'skedi_integration_id' => (string)$integration->getId(),
                'skedi_user_id' => (string)$integration->getUser()->getId()
            ];
            
            // Add event and booking IDs if available
            if ($eventId) {
                $privateProperties['skedi_event_id'] = (string)$eventId;
            }
            if ($bookingId) {
                $privateProperties['skedi_booking_id'] = (string)$bookingId;
                $privateProperties['skedi_source_id'] = 'booking_' . $bookingId;
            }
            
            $extendedProperties->setPrivate($privateProperties);
            $event->setExtendedProperties($extendedProperties);
            
            // Set description if provided
            if (!empty($options['description'])) {
                $event->setDescription($options['description']);
            }
            
            // Add attendees if provided
            if (!empty($options['attendees']) && is_array($options['attendees'])) {
                $attendees = [];
                foreach ($options['attendees'] as $attendee) {
                    $eventAttendee = new \Google\Service\Calendar\EventAttendee();
                    $eventAttendee->setEmail($attendee['email']);
                    if (!empty($attendee['name'])) {
                        $eventAttendee->setDisplayName($attendee['name']);
                    }
                    $attendees[] = $eventAttendee;
                }
                $event->setAttendees($attendees);
            }
            
            // Create the event in a temporary calendar to generate Meet link
            $calendarId = 'primary';
            $createdEvent = $service->events->insert($calendarId, $event, ['conferenceDataVersion' => 1]);
            
            // Extract Meet conference data
            $conferenceData = $createdEvent->getConferenceData();
            if (!$conferenceData || !$conferenceData->getEntryPoints()) {
                throw new IntegrationException('Failed to create Google Meet link');
            }
            
            // Find the Meet link in entry points
            $meetLink = null;
            foreach ($conferenceData->getEntryPoints() as $entryPoint) {
                if ($entryPoint->getEntryPointType() === 'video') {
                    $meetLink = $entryPoint->getUri();
                    break;
                }
            }
            
            if (!$meetLink) {
                throw new IntegrationException('No Google Meet link found in created event');
            }
            
            // Create a GoogleMeetEventEntity to store the Meet information
            $meetEvent = new GoogleMeetEventEntity();
            $meetEvent->setUser($integration->getUser());
            $meetEvent->setIntegration($integration);
            
            if ($eventId) {
                $meetEvent->setEventId($eventId);
            }
            
            if ($bookingId) {
                $meetEvent->setBookingId($bookingId);
            }
            
            $meetEvent->setMeetId($createdEvent->getId());
            $meetEvent->setMeetLink($meetLink);
            
            // Store full conference data as JSON
            $meetEvent->setConferenceData([
                'conferenceId' => $conferenceData->getConferenceId(),
                'conferenceType' => $conferenceData->getConferenceSolution()->getKey()->getType(),
                'entryPoints' => array_map(function($entryPoint) {
                    return [
                        'type' => $entryPoint->getEntryPointType(),
                        'uri' => $entryPoint->getUri(),
                        'label' => $entryPoint->getLabel()
                    ];
                }, $conferenceData->getEntryPoints()),
                'notes' => $conferenceData->getNotes(),
                'parameters' => $conferenceDataParams
            ]);
            
            $meetEvent->setTitle($title);
            $meetEvent->setDescription($options['description'] ?? null);
            $meetEvent->setStartTime($startTime);
            $meetEvent->setEndTime($endTime);
            $meetEvent->setStatus('active');
            
            $this->entityManager->persist($meetEvent);
            $this->entityManager->flush();
            
            return $meetEvent;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to create Google Meet link: ' . $e->getMessage());
        }
    }
    
    /**
     * Cleanup expired Google Meet events
     */
    public function cleanupExpiredMeetEvents(int $retentionDays = 7): int
    {
        try {
            $cutoffDate = new DateTime("-{$retentionDays} days");
            
            $filters = [
                [
                    'field' => 'endTime',
                    'operator' => 'less_than',
                    'value' => $cutoffDate
                ]
            ];
            
            $expiredEvents = $this->crudManager->findMany(
                GoogleMeetEventEntity::class,
                $filters,
                1,
                1000,
                []
            );
            
            $removedCount = 0;
            foreach ($expiredEvents as $event) {
                $this->entityManager->remove($event);
                $removedCount++;
            }
            
            if ($removedCount > 0) {
                $this->entityManager->flush();
            }
            
            return $removedCount;
        } catch (\Exception $e) {
            error_log('Failed to cleanup expired Meet events: ' . $e->getMessage());
            return 0;
        }
    }
}