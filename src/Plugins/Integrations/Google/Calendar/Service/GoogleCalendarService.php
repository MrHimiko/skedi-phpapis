<?php

namespace App\Plugins\Integrations\Google\Calendar\Service;

use App\Plugins\Integrations\Common\Abstract\BaseCalendarIntegration;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Google\Calendar\Entity\GoogleCalendarEventEntity;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Account\Service\UserAvailabilityService;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;
use DateTimeInterface;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Oauth2;

class GoogleCalendarService extends BaseCalendarIntegration
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        IntegrationRepository $integrationRepository,
        UserAvailabilityService $userAvailabilityService,
        CrudManager $crudManager
    ) {
        parent::__construct($entityManager, $integrationRepository, $userAvailabilityService, $crudManager);

        $this->clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID');
        $this->clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET');
        $this->redirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI');
    }

    
    /**
     * {@inheritdoc}
     */
    public function getProvider(): string
    {
        return 'google_calendar';
    }
    
    /**
     * {@inheritdoc}
     */
    protected function getEventEntityClass(): string
    {
        return GoogleCalendarEventEntity::class;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function getOAuthConfig(): array
    {
        return [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scope' => 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/userinfo.email'
        ];
    }
    
    /**
     * Create properly configured Google Client with CORRECT OAuth parameters
     * This is the key fix - consistent configuration across all methods
     */
    private function createBaseGoogleClient(): GoogleClient
    {
        $client = new GoogleClient();
        
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->setRedirectUri($this->redirectUri);
        
        $client->setScopes([
            'https://www.googleapis.com/auth/calendar',
            'https://www.googleapis.com/auth/userinfo.email'
        ]);
        
        // CRITICAL: These parameters ensure we ALWAYS get refresh tokens
        $client->setAccessType('offline');        // Required for refresh tokens
        $client->setPrompt('consent');           // Forces consent screen = guaranteed refresh token
        $client->setIncludeGrantedScopes(true); // Enables incremental authorization
        
        return $client;
    }

    /**
     * Get authorization URL with GUARANTEED refresh token parameters
     */
    public function getAuthUrl(): string
    {
        $client = $this->createBaseGoogleClient();
        
        $authUrl = $client->createAuthUrl();
        
        // Debug logging to verify correct parameters
        error_log('Google Calendar Auth URL: ' . $authUrl);
        
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
     * Get Google Client with existing token handling and auto-refresh
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
                        'Google Calendar access expired. Please reconnect your account. Error: ' . $e->getMessage()
                    );
                }
            }
        }
        
        return $client;
    }
    
    /**
     * {@inheritdoc}
     */
    protected function exchangeCodeForToken(string $code): array
    {
        $client = $this->createBaseGoogleClient();
        
        try {
            $accessToken = $client->fetchAccessTokenWithAuthCode($code);
            
            // Enhanced error handling
            if (isset($accessToken['error'])) {
                error_log('Google token exchange error: ' . json_encode($accessToken));
                throw new IntegrationException('Failed to get access token: ' . 
                    ($accessToken['error_description'] ?? $accessToken['error']));
            }
            
            // CRITICAL: Verify we got a refresh token
            if (!isset($accessToken['refresh_token'])) {
                error_log('No refresh token received! Response: ' . json_encode($accessToken));
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
            
            // Log successful token exchange
            error_log('Successfully received refresh token for Google Calendar');
            
            return $accessToken;
        } catch (\Exception $e) {
            if ($e instanceof IntegrationException) {
                throw $e;
            }
            throw new IntegrationException('Token exchange failed: ' . $e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
     protected function getUserInfo(array $tokenData): array
    {
        try {
            $client = $this->createBaseGoogleClient();
            $client->setAccessToken($tokenData);
            
            $oauth2 = new Oauth2($client);
            $userInfo = $oauth2->userinfo->get();
            
            return [
                'id' => $userInfo->getId(),
                'email' => $userInfo->getEmail()
            ];
        } catch (\Exception $e) {
            error_log('Failed to get user info: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Enhanced token refresh with retry logic and better error handling
     */
    protected function refreshToken(IntegrationEntity $integration): array
    {
        return $this->refreshTokenWithRetry($integration);
    }
    
    /**
     * Robust token refresh with retry logic
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
                error_log("Attempting to refresh Google Calendar token (attempt " . ($attempt + 1) . ")");
                
                $accessToken = $client->fetchAccessTokenWithRefreshToken($integration->getRefreshToken());
                
                if (isset($accessToken['error'])) {
                    error_log('Google token refresh error: ' . json_encode($accessToken));
                    
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
                    error_log('New refresh token received and saved');
                }
                
                // Ensure integration is marked as active
                $integration->setStatus('active');
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
                
                error_log('Successfully refreshed Google Calendar token');
                
                return $accessToken;
                
            } catch (IntegrationException $e) {
                throw $e; // Don't retry integration exceptions
            } catch (\Exception $e) {
                $attempt++;
                error_log("Token refresh attempt {$attempt} failed: " . $e->getMessage());
                
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
    protected function refreshTokenIfNeeded(IntegrationEntity $integration): void
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
                error_log('Proactively refreshed token for integration ID: ' . $integration->getId());
            } catch (\Exception $e) {
                error_log('Proactive token refresh failed: ' . $e->getMessage());
                throw $e;
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function syncEvents(IntegrationEntity $integration, DateTime $startDate, DateTime $endDate): array
    {
        try {
            // Check rate limit
            $this->checkRateLimit($integration, 'sync');
            
            $user = $integration->getUser();
            
            // Proactively refresh token before making API calls
            $this->refreshTokenIfNeeded($integration);
            
            $client = $this->getGoogleClient($integration);
            $service = new GoogleCalendar($client);
            
            // Get calendar list (with caching)
            $calendarList = $this->remember(
                $this->generateCacheKey('google_calendar', $integration->getId(), 'calendars'),
                function() use ($service) {
                    return $service->calendarList->listCalendarList();
                },
                $this->cacheTTLs['calendars_list']
            );
            
            $savedEvents = [];
            
            // Format dates for Google API
            $timeMin = $startDate->format('c');
            $timeMax = $endDate->format('c');
            
            $this->entityManager->beginTransaction();
            
            try {
                foreach ($calendarList->getItems() as $calendarListEntry) {
                    $calendarId = $calendarListEntry->getId();
                    $calendarName = $calendarListEntry->getSummary();
                    
                    // Only sync primary and selected calendars
                    $isPrimary = $calendarListEntry->getPrimary() ?? false;
                    $isSelected = $calendarListEntry->getSelected() ?? false;
                    
                    if (!$isPrimary && !$isSelected) {
                        continue;
                    }
                    
                    $calendarEventIds = [];
                    $pageToken = null;
                    
                    do {
                        $optParams = [
                            'timeMin' => $timeMin,
                            'timeMax' => $timeMax,
                            'showDeleted' => true,
                            'singleEvents' => true,
                            'orderBy' => 'startTime',
                            'maxResults' => 250
                        ];
                        
                        if ($pageToken) {
                            $optParams['pageToken'] = $pageToken;
                        }
                        
                        $eventsResult = $service->events->listEvents($calendarId, $optParams);
                        $events = $eventsResult->getItems();
                        
                        foreach ($events as $event) {
                            // Skip events created by our application
                            if ($this->isSkediEvent($event)) {
                                $calendarEventIds[] = $event->getId();
                                continue;
                            }
                            
                            $savedEvent = $this->saveEvent($integration, $user, $event, $calendarId, $calendarName);
                            if ($savedEvent) {
                                $this->entityManager->flush();
                                $savedEvents[] = $savedEvent;
                                $calendarEventIds[] = $event->getId();
                            }
                        }
                        
                        $pageToken = $eventsResult->getNextPageToken();
                    } while ($pageToken);
                    
                    // Clean up deleted events
                    $this->cleanupDeletedEvents($user, $calendarEventIds, $calendarId, $startDate, $endDate);
                }
                
                // Update last synced
                $integration->setLastSynced(new DateTime());
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
                
                $this->entityManager->commit();
                
                // Sync user availability
                $this->syncUserAvailability($user, $savedEvents);
                
                return $savedEvents;
            } catch (\Exception $e) {
                $this->entityManager->rollback();
                throw $e;
            }
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to sync calendar events: ' . $e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function getCalendars(IntegrationEntity $integration): array
    {
        // Use caching for calendar list
        $cacheKey = $this->generateCacheKey('google_calendar', $integration->getId(), 'calendars_list');
        
        return $this->remember($cacheKey, function() use ($integration) {
            try {
                // Check rate limit
                $this->checkRateLimit($integration, 'default');
                
                // Proactively refresh token before making API calls
                $this->refreshTokenIfNeeded($integration);
                
                $client = $this->getGoogleClient($integration);
                $service = new GoogleCalendar($client);
                
                $calendarList = $service->calendarList->listCalendarList();
                $calendars = [];
                
                foreach ($calendarList->getItems() as $calendarListEntry) {
                    $calendars[] = [
                        'id' => $calendarListEntry->getId(),
                        'summary' => $calendarListEntry->getSummary(),
                        'description' => $calendarListEntry->getDescription(),
                        'primary' => $calendarListEntry->getPrimary(),
                        'access_role' => $calendarListEntry->getAccessRole(),
                        'background_color' => $calendarListEntry->getBackgroundColor(),
                        'foreground_color' => $calendarListEntry->getForegroundColor(),
                        'selected' => $calendarListEntry->getSelected(),
                        'time_zone' => $calendarListEntry->getTimeZone()
                    ];
                }
                
                // Update integration config
                $config = $integration->getConfig() ?: [];
                $config['calendars'] = $calendars;
                $integration->setConfig($config);
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
                
                return $calendars;
            } catch (\Exception $e) {
                throw new IntegrationException('Failed to fetch calendars: ' . $e->getMessage());
            }
        }, $this->cacheTTLs['calendars_list']);
    }
    
    /**
     * {@inheritdoc}
     */
    public function createCalendarEvent(
        IntegrationEntity $integration,
        string $title,
        DateTimeInterface $startDateTime,
        DateTimeInterface $endDateTime,
        array $options = []
    ): array {
        try {
            // Check rate limit
            $this->checkRateLimit($integration, 'create');
            
            // Proactively refresh token before making API calls
            $this->refreshTokenIfNeeded($integration);
            
            $client = $this->getGoogleClient($integration);
            $service = new GoogleCalendar($client);
            
            $event = new \Google\Service\Calendar\Event();
            $event->setSummary($title);
            
            // Set times
            $start = new \Google\Service\Calendar\EventDateTime();
            $start->setDateTime($startDateTime->format('c'));
            $event->setStart($start);
            
            $end = new \Google\Service\Calendar\EventDateTime();
            $end->setDateTime($endDateTime->format('c'));
            $event->setEnd($end);
            
            // Optional fields
            if (!empty($options['description'])) {
                $event->setDescription($options['description']);
            }
            
            if (!empty($options['location'])) {
                $event->setLocation($options['location']);
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
            
            // Conference data
            if (!empty($options['conference_data'])) {
                $this->setConferenceData($event, $options['conference_data']);
            }
            
            // ALWAYS set extended properties to identify Skedi events
            $extendedProperties = new \Google\Service\Calendar\EventExtendedProperties();
            $privateProperties = [
                'skedi_event' => 'true',
                'skedi_created_at' => (new DateTime())->format('c'),
                'skedi_integration_id' => (string)$integration->getId(),
                'skedi_user_id' => (string)$integration->getUser()->getId()
            ];
            
            // Add source_id if provided
            if (!empty($options['source_id'])) {
                $privateProperties['skedi_source_id'] = $options['source_id'];
            }
            
            // Add event type if we can determine it
            if (!empty($options['skedi_event_type'])) {
                $privateProperties['skedi_event_type'] = $options['skedi_event_type'];
            }
            
            $extendedProperties->setPrivate($privateProperties);
            $event->setExtendedProperties($extendedProperties);
            
            $calendarId = $options['calendar_id'] ?? 'primary';
            $createParams = [];
            
            if (!empty($options['conference_data'])) {
                $createParams['conferenceDataVersion'] = 1;
            }
            
            $createdEvent = $service->events->insert($calendarId, $event, $createParams);
            
            // Get meet link if created
            $meetLink = null;
            if ($createdEvent->getConferenceData() && $createdEvent->getConferenceData()->getEntryPoints()) {
                foreach ($createdEvent->getConferenceData()->getEntryPoints() as $entryPoint) {
                    if ($entryPoint->getEntryPointType() === 'video') {
                        $meetLink = $entryPoint->getUri();
                        break;
                    }
                }
            }
            
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            return [
                'google_event_id' => $createdEvent->getId(),
                'html_link' => $createdEvent->getHtmlLink(),
                'meet_link' => $meetLink,
                'calendar_id' => $calendarId,
                'start_time' => $startDateTime->format('c'),
                'end_time' => $endDateTime->format('c'),
                'status' => $createdEvent->getStatus()
            ];
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to create Google Calendar event: ' . $e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    protected function saveEvent(
        IntegrationEntity $integration,
        UserEntity $user,
        $event,
        string $calendarId,
        string $calendarName
    ): ?GoogleCalendarEventEntity {
        try {
            if ($event->getStatus() === 'cancelled' || !$event->getStart() || !$event->getEnd()) {
                return null;
            }
            
            // Check if exists
            $existingEvent = $this->entityManager->getRepository(GoogleCalendarEventEntity::class)->findOneBy([
                'user' => $user,
                'googleEventId' => $event->getId(),
                'calendarId' => $calendarId
            ]);
            
            // Parse times
            $isAllDay = false;
            $startTime = null;
            $endTime = null;
            
            $start = $event->getStart();
            $end = $event->getEnd();
            
            if ($start->date) {
                $isAllDay = true;
                $startTime = new DateTime($start->date, new \DateTimeZone('UTC'));
                $endTime = new DateTime($end->date, new \DateTimeZone('UTC'));
            } elseif ($start->dateTime) {
                $timezone = $start->timeZone ?: 'UTC';
                $startTime = new DateTime($start->dateTime, new \DateTimeZone($timezone));
                $endTime = new DateTime($end->dateTime, new \DateTimeZone($timezone));
                $startTime->setTimezone(new \DateTimeZone('UTC'));
                $endTime->setTimezone(new \DateTimeZone('UTC'));
            } else {
                return null; // No valid time
            }
            
            if ($existingEvent) {
                // Update existing
                $existingEvent->setTitle($event->getSummary() ?: 'Untitled Event');
                $existingEvent->setDescription($event->getDescription());
                $existingEvent->setLocation($event->getLocation());
                $existingEvent->setStartTime($startTime);
                $existingEvent->setEndTime($endTime);
                $existingEvent->setIsAllDay($isAllDay);
                $existingEvent->setStatus($event->getStatus());
                $existingEvent->setTransparency($event->getTransparency());
                $existingEvent->setCalendarName($calendarName);
                $existingEvent->setEtag($event->getEtag());
                $existingEvent->setHtmlLink($event->getHtmlLink());
                
                if ($event->getOrganizer()) {
                    $existingEvent->setOrganizerEmail($event->getOrganizer()->getEmail());
                    $existingEvent->setIsOrganizer($event->getOrganizer()->getSelf() ?? false);
                }
                
                $existingEvent->setSyncedAt(new DateTime());
                $this->entityManager->persist($existingEvent);
                
                return $existingEvent;
            } else {
                // Create new
                $newEvent = new GoogleCalendarEventEntity();
                $newEvent->setUser($user);
                $newEvent->setIntegration($integration);
                $newEvent->setGoogleEventId($event->getId());
                $newEvent->setCalendarId($calendarId);
                $newEvent->setCalendarName($calendarName);
                $newEvent->setTitle($event->getSummary() ?: 'Untitled Event');
                $newEvent->setDescription($event->getDescription());
                $newEvent->setLocation($event->getLocation());
                $newEvent->setStartTime($startTime);
                $newEvent->setEndTime($endTime);
                $newEvent->setIsAllDay($isAllDay);
                $newEvent->setStatus($event->getStatus());
                $newEvent->setTransparency($event->getTransparency());
                $newEvent->setEtag($event->getEtag());
                $newEvent->setHtmlLink($event->getHtmlLink());
                
                if ($event->getOrganizer()) {
                    $newEvent->setOrganizerEmail($event->getOrganizer()->getEmail());
                    $newEvent->setIsOrganizer($event->getOrganizer()->getSelf() ?? false);
                }
                
                $newEvent->setSyncedAt(new DateTime());
                $this->entityManager->persist($newEvent);
                
                return $newEvent;
            }
        } catch (\Exception $e) {
            error_log('Error saving event: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Clean up deleted events
     */
    private function cleanupDeletedEvents(UserEntity $user, array $keepEventIds, string $calendarId, DateTime $startDate, DateTime $endDate): void
    {
        try {
            $filters = [
                [
                    'field' => 'startTime',
                    'operator' => 'greater_than_or_equal',
                    'value' => $startDate
                ],
                [
                    'field' => 'endTime',
                    'operator' => 'less_than_or_equal',
                    'value' => $endDate
                ],
                [
                    'field' => 'calendarId',
                    'operator' => 'equals',
                    'value' => $calendarId
                ]
            ];
            
            $events = $this->crudManager->findMany(
                GoogleCalendarEventEntity::class,
                $filters,
                1,
                1000,
                ['user' => $user]
            );
            
            foreach ($events as $event) {
                if (!in_array($event->getGoogleEventId(), $keepEventIds)) {
                    $event->setStatus('cancelled');
                    $this->entityManager->persist($event);
                }
            }
        } catch (\Exception $e) {
            // Continue
        }
    }
    
    /**
     * Check if event is created by Skedi
     */
    private function isSkediEvent(\Google\Service\Calendar\Event $event): bool
    {
        // Check extended properties first (most reliable)
        if ($event->getExtendedProperties() && $event->getExtendedProperties()->getPrivate()) {
            $private = $event->getExtendedProperties()->getPrivate();
            
            // If it has skedi_event property, it's definitely ours
            if (isset($private['skedi_event']) && $private['skedi_event'] === 'true') {
                return true;
            }
            
            // Legacy check for older events
            if (isset($private['skedi_source_id'])) {
                return true;
            }
        }
        
        // Fallback: Check description for known patterns
        $description = $event->getDescription() ?? '';
        if (strpos($description, 'Booking for:') !== false || 
            strpos($description, 'Booking details:') !== false) {
            return true;
        }
        
        // Check if title starts with "Skedi:"
        $title = $event->getSummary() ?? '';
        if (strpos($title, 'Skedi:') === 0) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Set conference data on event
     */
    private function setConferenceData(\Google\Service\Calendar\Event $event, array $conferenceData): void
    {
        if (isset($conferenceData['type'])) {
            if ($conferenceData['type'] === 'existingMeet' && isset($conferenceData['meetId'])) {
                // Link to existing Meet
                try {
                    // First, we need to get the existing event to copy its conference data
                    $service = new GoogleCalendar($this->getGoogleClient());
                    $existingEvent = $service->events->get('primary', $conferenceData['meetId']);
                    
                    if ($existingEvent && $existingEvent->getConferenceData()) {
                        // Copy the conference data from the existing event
                        $event->setConferenceData($existingEvent->getConferenceData());
                    }
                } catch (\Exception $e) {
                    // If we can't get the existing event, create a new Meet link
                    $this->createNewMeetConference($event);
                }
            } else if ($conferenceData['type'] === 'hangoutsMeet') {
                if (isset($conferenceData['link'])) {
                    // Use existing link - but this usually doesn't work with Google API
                    // Google prefers to create its own links
                    $this->createNewMeetConference($event);
                } else {
                    // Create new Meet conference
                    $this->createNewMeetConference($event);
                }
            }
        }
    }

    private function createNewMeetConference(\Google\Service\Calendar\Event $event): void
    {
        $conference = new \Google\Service\Calendar\ConferenceData();
        $createRequest = new \Google\Service\Calendar\CreateConferenceRequest();
        $createRequest->setRequestId('meet_' . uniqid());
        $createRequest->setConferenceSolutionKey(
            new \Google\Service\Calendar\ConferenceSolutionKey(['type' => 'hangoutsMeet'])
        );
        
        $conference->setCreateRequest($createRequest);
        $event->setConferenceData($conference);
    }

    public function testSaveEvent(IntegrationEntity $integration): array
    {
        try {
            // Create a test event
            $testEvent = new GoogleCalendarEventEntity();
            $testEvent->setUser($integration->getUser());
            $testEvent->setIntegration($integration);
            $testEvent->setGoogleEventId('test_' . uniqid());
            $testEvent->setCalendarId('primary');
            $testEvent->setCalendarName('Test Calendar');
            $testEvent->setTitle('Test Event from API');
            $testEvent->setDescription('This is a test event');
            $testEvent->setStartTime(new DateTime('+1 day'));
            $testEvent->setEndTime(new DateTime('+1 day 1 hour'));
            $testEvent->setIsAllDay(false);
            $testEvent->setStatus('confirmed');
            $testEvent->setSyncedAt(new DateTime());
            
            $this->entityManager->persist($testEvent);
            $this->entityManager->flush();
            
            // Try to retrieve it
            $saved = $this->entityManager->getRepository(GoogleCalendarEventEntity::class)->find($testEvent->getId());
            
            return [
                'save_success' => true,
                'event_id' => $testEvent->getId(),
                'retrieved' => $saved ? true : false
            ];
        } catch (\Exception $e) {
            return [
                'save_success' => false,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ];
        }
    }

    /**
     * Delete Google Calendar event for a cancelled booking
     * 
     * @param IntegrationEntity $integration The Google Calendar integration
     * @param \App\Plugins\Events\Entity\EventBookingEntity $booking The cancelled booking
     * @return void
     * @throws IntegrationException
     */
    public function deleteEventForCancelledBooking(
        IntegrationEntity $integration, 
        \App\Plugins\Events\Entity\EventBookingEntity $booking
    ): void {
        try {
            // Check rate limit
            $this->checkRateLimit($integration, 'delete');
            
            // Refresh token if needed
            $this->refreshTokenIfNeeded($integration);
            
            $client = $this->getGoogleClient($integration);
            $service = new GoogleCalendar($client);
            
            // Build unique source ID for this booking
            $sourceId = 'booking_' . $booking->getId();
            
            // Get booking details
            $eventName = $booking->getEvent()->getName();
            $bookingStart = $booking->getStartTime();
            $bookingEnd = $booking->getEndTime();
            
            // Possible title variations to search for
            $titleVariations = [
                $eventName,                                                              // Original name
                'Skedi: ' . $eventName,                                                 // With Skedi prefix
                $eventName . ' - ' . $bookingStart->format('M j, Y g:i A'),           // With date/time
                'Skedi: ' . $eventName . ' - ' . $bookingStart->format('M j, Y g:i A') // With both
            ];
            
            // Try multiple approaches to find the event
            $deleted = false;
            $foundEvents = [];
            
            // Approach 1: Search by extended properties (most reliable)
            try {
                $events = $service->events->listEvents('primary', [
                    'privateExtendedProperty' => 'skedi_source_id=' . $sourceId,
                    'showDeleted' => false,
                    'singleEvents' => true
                ]);
                
                foreach ($events->getItems() as $event) {
                    $foundEvents[] = ['calendarId' => 'primary', 'eventId' => $event->getId()];
                }
            } catch (\Exception $e) {
                // Continue to next approach
            }
            
            // Approach 2: Search by time range and title variations
            if (empty($foundEvents)) {
                try {
                    // Search for events in the same time range
                    $timeMin = clone $bookingStart;
                    $timeMax = clone $bookingEnd;
                    $timeMin->modify('-1 minute');
                    $timeMax->modify('+1 minute');
                    
                    foreach ($titleVariations as $searchTitle) {
                        $events = $service->events->listEvents('primary', [
                            'timeMin' => $timeMin->format('c'),
                            'timeMax' => $timeMax->format('c'),
                            'q' => $searchTitle,
                            'showDeleted' => false,
                            'singleEvents' => true
                        ]);
                        
                        foreach ($events->getItems() as $event) {
                            // Check if this is likely our event
                            $eventStart = new DateTime($event->getStart()->getDateTime() ?: $event->getStart()->getDate());
                            $eventEnd = new DateTime($event->getEnd()->getDateTime() ?: $event->getEnd()->getDate());
                            
                            // Compare times (within 5 minutes tolerance)
                            $startDiff = abs($eventStart->getTimestamp() - $bookingStart->getTimestamp());
                            $endDiff = abs($eventEnd->getTimestamp() - $bookingEnd->getTimestamp());
                            
                            if ($startDiff <= 300 && $endDiff <= 300) {
                                // Check if it's a Skedi-created event
                                $description = $event->getDescription() ?? '';
                                $eventTitle = $event->getSummary() ?? '';
                                
                                // Check various indicators
                                $isSkediEvent = false;
                                
                                // Check description
                                if (strpos($description, 'Booking for:') !== false || 
                                    strpos($description, $sourceId) !== false) {
                                    $isSkediEvent = true;
                                }
                                
                                // Check if title matches any variation
                                foreach ($titleVariations as $titleVariation) {
                                    if ($eventTitle === $titleVariation) {
                                        $isSkediEvent = true;
                                        break;
                                    }
                                }
                                
                                if ($isSkediEvent) {
                                    $foundEvents[] = ['calendarId' => 'primary', 'eventId' => $event->getId()];
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Continue
                }
            }
            
            // Approach 3: Check all user calendars with extended properties
            if (empty($foundEvents)) {
                try {
                    $calendarList = $service->calendarList->listCalendarList();
                    
                    foreach ($calendarList->getItems() as $calendar) {
                        if (!$calendar->getPrimary() && !$calendar->getSelected()) {
                            continue;
                        }
                        
                        try {
                            $events = $service->events->listEvents($calendar->getId(), [
                                'privateExtendedProperty' => 'skedi_source_id=' . $sourceId,
                                'showDeleted' => false
                            ]);
                            
                            foreach ($events->getItems() as $event) {
                                $foundEvents[] = ['calendarId' => $calendar->getId(), 'eventId' => $event->getId()];
                            }
                        } catch (\Exception $e) {
                            // Continue
                        }
                    }
                } catch (\Exception $e) {
                    // Continue
                }
            }
            
            // Delete all found events
            foreach ($foundEvents as $eventInfo) {
                try {
                    $service->events->delete($eventInfo['calendarId'], $eventInfo['eventId']);
                    $deleted = true;
                } catch (\Google\Service\Exception $e) {
                    if ($e->getCode() !== 404) {
                        // Log but continue
                    }
                }
            }
            
            // Also update any local database records using CrudManager
            try {
                // Search with more flexible criteria
                $filters = [
                    [
                        'field' => 'startTime',
                        'operator' => 'between',
                        'value' => [
                            (clone $bookingStart)->modify('-5 minutes'),
                            (clone $bookingStart)->modify('+5 minutes')
                        ]
                    ],
                    [
                        'field' => 'status',
                        'operator' => 'not_equals',
                        'value' => 'cancelled'
                    ]
                ];
                
                $googleEvents = $this->crudManager->findMany(
                    GoogleCalendarEventEntity::class,
                    $filters,
                    1,
                    1000,
                    [
                        'user' => $integration->getUser(),
                        'integration' => $integration
                    ]
                );
                
                foreach ($googleEvents as $googleEvent) {
                    // Check if this matches our booking (within time tolerance and title match)
                    $titleMatches = false;
                    foreach ($titleVariations as $titleVariation) {
                        if ($googleEvent->getTitle() === $titleVariation) {
                            $titleMatches = true;
                            break;
                        }
                    }
                    
                    if ($titleMatches) {
                        $this->crudManager->update($googleEvent, [
                            'status' => 'cancelled'
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Continue
            }
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to delete Google Calendar event: ' . $e->getMessage());
        }
    }
    
    /**
     * Delete all Google Calendar events associated with a booking
     * This method handles multiple assignees and their integrations
     * 
     * @param \App\Plugins\Events\Entity\EventBookingEntity $booking
     * @return void
     */
    public function deleteGoogleEventsForBooking(\App\Plugins\Events\Entity\EventBookingEntity $booking): void {
        try {
            $event = $booking->getEvent();
            
            // Get all assignees for this event using CrudManager
            $assignees = $this->crudManager->findMany(
                'App\Plugins\Events\Entity\EventAssigneeEntity',
                [],
                1,
                1000,
                ['event' => $event]
            );
            
            foreach ($assignees as $assignee) {
                $user = $assignee->getUser();
                
                // Find Google Calendar integrations for this user using CrudManager
                $integrations = $this->crudManager->findMany(
                    IntegrationEntity::class,
                    [],
                    1,
                    100,
                    [
                        'user' => $user,
                        'provider' => 'google_calendar',
                        'status' => 'active'
                    ]
                );
                
                foreach ($integrations as $integration) {
                    try {
                        $this->deleteEventForCancelledBooking($integration, $booking);
                    } catch (\Exception $e) {
                        // Continue to next integration
                        // Don't let one failure stop the others
                    }
                }
            }
        } catch (\Exception $e) {
            // Silently fail - we don't want Google errors to break booking operations
        }
    }
}