<?php


namespace App\Plugins\Integrations\Microsoft\Outlook\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Common\Service\IntegrationService;

use App\Plugins\Integrations\Microsoft\Outlook\Entity\OutlookCalendarEventEntity;
use App\Plugins\Account\Service\UserAvailabilityService;
use App\Service\CrudManager;
use App\Exception\CrudException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use DateTime;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client as HttpClient;

// Microsoft Graph SDK v2 imports
use Microsoft\Graph\GraphServiceClient;
use Microsoft\Graph\Generated\Models;
use Microsoft\Kiota\Abstractions\Authentication\AuthenticationProvider;
use Microsoft\Kiota\Abstractions\Authentication\AllowedHostsValidator;
use Microsoft\Kiota\Abstractions\ApiException;
use Microsoft\Graph\Core\GraphClientFactory;
use Microsoft\Graph\Core\GraphConstants;
use Microsoft\Graph\Core\Authentication\GraphPhpLeagueAuthenticationProvider;
use Microsoft\Kiota\Authentication\Oauth\TokenRequestContext;
use Microsoft\Kiota\Authentication\Oauth\ClientCredentialContext;
use Microsoft\Kiota\Http\GuzzleRequestAdapter;
use Http\Promise\Promise;
use Http\Promise\FulfilledPromise;
use Http\Promise\RejectedPromise;


/**
 * Custom AuthenticationProvider for existing tokens
 */
class DirectAuthenticationProvider implements AuthenticationProvider 
{
    private string $accessToken;
    private AllowedHostsValidator $allowedHostsValidator;
    
    public function __construct(string $accessToken) 
    {
        $this->accessToken = $accessToken;
        $this->allowedHostsValidator = new AllowedHostsValidator();
        
        // Ensure all Graph API endpoints are allowed
        $this->allowedHostsValidator->setAllowedHosts([
            "graph.microsoft.com",
            "graph.microsoft.us",
            "dod-graph.microsoft.us",
            "graph.microsoft.de",
            "microsoftgraph.chinacloudapi.cn",
            "outlook.office.com"
        ]);
    }
    
    public function authenticateRequest(
        \Microsoft\Kiota\Abstractions\RequestInformation $request,
        array $additionalAuthenticationContext = []
    ): Promise {
        // First validate the host
        if (!$this->allowedHostsValidator->isUrlHostValid($request->getUri())) {
            return new RejectedPromise(
                new \InvalidArgumentException("Url host is not valid: " . $request->getUri())
            );
        }
        
        // Set authorization header
        $request->addHeader('Authorization', 'Bearer ' . $this->accessToken);
        
        // Add additional headers for compatibility
        $request->addHeader('Accept', 'application/json');
        
        return new FulfilledPromise(null);
    }
    
    public function getAllowedHostsValidator(): AllowedHostsValidator
    {
        return $this->allowedHostsValidator;
    }
}

class OutlookCalendarService extends IntegrationService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $tenantId;
    private string $authority;
    private LoggerInterface $logger;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        IntegrationRepository $integrationRepository,
        UserAvailabilityService $userAvailabilityService,
        CrudManager $crudManager,
        ParameterBagInterface $parameterBag,
        LoggerInterface $logger
    ) {
        parent::__construct($entityManager, $integrationRepository, $userAvailabilityService, $crudManager);
        
        $this->logger = $logger;
        
        try {
           /* REMOVED TOKENS FROM HERE */
        } catch (\Exception $e) {
            /* REMOVED TOKENS FROM HERE */
        }
    }   



    /**
     * Get client ID
     */
    public function getClientId(): string
    {
        return $this->clientId;
    }

    /**
     * Get redirect URI
     */
    public function getRedirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * Get tenant ID
     */
    public function getTenantId(): string
    {
        return $this->tenantId ?: 'common';
    }

    /**
     * Get authority
     */
    public function getAuthority(): string
    {
        return $this->authority;
    }


    /**
     * Get Microsoft Graph instance for V2 SDK
     */
    private function getGraphClient(IntegrationEntity $integration): GraphServiceClient
    {
        if (!$integration->getAccessToken()) {
            throw new IntegrationException('No access token available');
        }
        
        // Check if token needs refresh
        if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
            $this->refreshToken($integration);
        }
        
        try {
            // Create an auth provider with the updated token
            $authProvider = new DirectAuthenticationProvider($integration->getAccessToken());
            
            // Create a request adapter with the auth provider
            $adapter = new GuzzleRequestAdapter($authProvider);
            
            // Set explicit base URL to ensure consistent endpoint access
            $adapter->setBaseUrl('https://graph.microsoft.com/v1.0');
            
            // Create the GraphServiceClient with the adapter
            return GraphServiceClient::createWithRequestAdapter($adapter);
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to create Graph client: ' . $e->getMessage());
        }
    }

    /**
     * Get OAuth URL
     */
    public function getAuthUrl(): string
    {
        $tenant = $this->tenantId ?: 'common';
        $authUrl = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize";
        
        $scopes = [
            'offline_access',
            'Calendars.Read',
            'Calendars.ReadWrite', 
            'User.Read'
        ];
        
        $params = [
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri,
            'response_mode' => 'query',
            'scope' => implode(' ', $scopes),
            'state' => uniqid('', true),
            'prompt' => 'consent'  
        ];
        
        return $authUrl . '?' . http_build_query($params);
    }

    /**
     * Handle OAuth callback and exchange code for tokens
     */
    public function handleAuthCallback(UserEntity $user, string $code): IntegrationEntity
    {
        try {
            $tenant = $this->tenantId ?: 'common';
            $tokenUrl = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token";
            
            $scopes = [
                'offline_access',
                'Calendars.Read',
                'Calendars.ReadWrite',
                'User.Read'
            ];
            
            $client = new \GuzzleHttp\Client([
                'http_errors' => false
            ]);
            
            $response = $client->post($tokenUrl, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                    'grant_type' => 'authorization_code',
                    'scope' => implode(' ', $scopes)
                ]
            ]);
            
            $responseBody = json_decode((string) $response->getBody(), true);
            
            if (isset($responseBody['error'])) {
                throw new IntegrationException('Token exchange failed: ' . 
                    ($responseBody['error_description'] ?? $responseBody['error']));
            }
            
            // Create expiration date
            $expiresIn = isset($responseBody['expires_in']) ? $responseBody['expires_in'] : 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            // Get user info from Microsoft Graph with the obtained token
            $accessToken = $responseBody['access_token'];
            $authProvider = new DirectAuthenticationProvider($accessToken);
            $adapter = new GuzzleRequestAdapter($authProvider);
            $graphClient = GraphServiceClient::createWithRequestAdapter($adapter);
            
            $outlookUser = null;
            try {
                $outlookUser = $graphClient->me()->get()->wait();
            } catch (ApiException $e) {
                $this->logger->warning('Could not fetch Outlook user info: ' . $e->getMessage());
            }
            
            // Use Outlook email if available, otherwise fall back to user's system email
            $outlookEmail = $outlookUser ? $outlookUser->getMail() : null;
            $outlookUserId = $outlookUser ? $outlookUser->getId() : null;
            
            $integrationName = 'Outlook Calendar';
            if ($outlookEmail) {
                $integrationName .= ' (' . $outlookEmail . ')';
            } else {
                $integrationName .= ' (' . $user->getEmail() . ')';
            }
            
            // Use Outlook user ID if available, otherwise generate one
            $externalId = $outlookUserId ?? 'outlook_' . uniqid();
            
            // Check if this user already has an Outlook Calendar integration
            $existingIntegration = $this->integrationRepository->findOneBy([
                'user' => $user,
                'provider' => 'outlook_calendar',
                'status' => 'active'
            ]);
            
            if ($existingIntegration) {
                // Update existing integration
                $existingIntegration->setAccessToken($responseBody['access_token']);
                $existingIntegration->setTokenExpires($expiresAt);
                
                // Update name and external ID if we got new info
                if ($outlookEmail) {
                    $existingIntegration->setName($integrationName);
                }
                
                if ($outlookUserId) {
                    $existingIntegration->setExternalId($externalId);
                }
                
                // Only update refresh token if a new one was provided
                if (isset($responseBody['refresh_token'])) {
                    $existingIntegration->setRefreshToken($responseBody['refresh_token']);
                }
                
                // Update config with Outlook email
                $config = $existingIntegration->getConfig() ?? [];
                if ($outlookEmail) {
                    $config['outlook_email'] = $outlookEmail;
                }
                $existingIntegration->setConfig($config);
                
                $this->entityManager->persist($existingIntegration);
                $this->entityManager->flush();
                
                $integration = $existingIntegration;
            } else {
                // Create new integration
                $integration = new IntegrationEntity();
                $integration->setUser($user);
                $integration->setProvider('outlook_calendar');
                $integration->setName($integrationName);
                $integration->setExternalId($externalId);
                $integration->setAccessToken($responseBody['access_token']);
                
                if (isset($responseBody['refresh_token'])) {
                    $integration->setRefreshToken($responseBody['refresh_token']);
                }
                
                $integration->setTokenExpires($expiresAt);
                $integration->setScopes('offline_access User.Read Calendars.ReadWrite');
                
                // Store Outlook email in the config
                $config = [
                    'calendars' => []
                ];
                
                if ($outlookEmail) {
                    $config['outlook_email'] = $outlookEmail;
                }
                
                $integration->setConfig($config);
                $integration->setStatus('active');
                
                $this->entityManager->persist($integration);
                $this->entityManager->flush();
            }
            
            // Perform initial sync as a background process
            try {
                // Sync events for the next 30 days to start
                $startDate = new DateTime('today');
                $endDate = new DateTime('+30 days');
                
                // Also sync events from the past 7 days
                $pastStartDate = new DateTime('-7 days');
                
                // First sync past events 
                $this->syncEvents($integration, $pastStartDate, $startDate);
                
                // Then sync future events
                $this->syncEvents($integration, $startDate, $endDate);
            } catch (\Exception $e) {
                // Log but don't fail the auth process
                $this->logger->warning('Initial calendar sync failed: ' . $e->getMessage());
            }
            
            return $integration;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to authenticate with Outlook: ' . $e->getMessage());
        }
    }

    /**
     * Refresh token
     */
    public function refreshToken(IntegrationEntity $integration): void
    {
        if (!$integration->getRefreshToken()) {
            throw new IntegrationException('No refresh token available');
        }
        
        try {
            $tenant = $this->tenantId ?: 'common';
            $tokenUrl = "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token";
            
            $scopes = [
                'offline_access',
                'Calendars.Read',
                'Calendars.ReadWrite',
                'User.Read'
            ];
            
            $client = new \GuzzleHttp\Client([
                'http_errors' => false,
                'timeout' => 15
            ]);
            
            $response = $client->post($tokenUrl, [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'refresh_token' => $integration->getRefreshToken(),
                    'redirect_uri' => $this->redirectUri,
                    'grant_type' => 'refresh_token',
                    'scope' => implode(' ', $scopes)
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = json_decode((string) $response->getBody(), true);
            
            // If we didn't get a 200 OK response, throw an exception with details
            if ($statusCode !== 200) {
                throw new IntegrationException('Token refresh failed with status code ' . $statusCode . 
                    ': ' . ($responseBody['error_description'] ?? $responseBody['error'] ?? 'Unknown error'));
            }
            
            // Check for error in response body
            if (isset($responseBody['error'])) {
                throw new IntegrationException('Token refresh failed: ' . 
                    ($responseBody['error_description'] ?? $responseBody['error']));
            }
            
            // Update token in database
            $expiresIn = isset($responseBody['expires_in']) ? $responseBody['expires_in'] : 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            $integration->setAccessToken($responseBody['access_token']);
            $integration->setTokenExpires($expiresAt);
            
            // Only update refresh token if a new one was provided
            if (isset($responseBody['refresh_token'])) {
                $integration->setRefreshToken($responseBody['refresh_token']);
            }
            
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to refresh token: ' . $e->getMessage());
        }
    }

    /**
     * Get user's Outlook Calendar integration
     */
    public function getUserIntegration(UserEntity $user, ?int $integrationId = null): ?IntegrationEntity
    {
        if ($integrationId) {
            $integration = $this->integrationRepository->find($integrationId);
            if ($integration && $integration->getUser()->getId() === $user->getId() && 
                $integration->getProvider() === 'outlook_calendar' && 
                $integration->getStatus() === 'active') {
                return $integration;
            }
            return null;
        }
        
        // Get the most recently created active integration
        return $this->integrationRepository->findOneBy(
            [
                'user' => $user,
                'provider' => 'outlook_calendar',
                'status' => 'active'
            ],
            ['created' => 'DESC']
        );
    }

    /**
     * Get Outlook calendars list
     */
    public function getCalendars(IntegrationEntity $integration): array
    {
        try {
            $accessToken = $integration->getAccessToken();
            
            // Use direct API call since the token is valid for Graph API
            $client = new \GuzzleHttp\Client([
                'http_errors' => false,
                'timeout' => 15
            ]);
            
            // Try different Graph API endpoints
            $endpoints = [
                'v1_calendars' => 'https://graph.microsoft.com/v1.0/me/calendars',
                'v1_calendar' => 'https://graph.microsoft.com/v1.0/me/calendar',
                'beta_calendars' => 'https://graph.microsoft.com/beta/me/calendars'
            ];
            
            $results = [];
            $calendars = [];
            
            foreach ($endpoints as $name => $url) {
                $response = $client->get($url, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $accessToken,
                        'Accept' => 'application/json'
                    ]
                ]);
                
                $statusCode = $response->getStatusCode();
                $results[$name] = ['status_code' => $statusCode];
                
                if ($statusCode === 200) {
                    $responseBody = $response->getBody()->getContents();
                    $data = json_decode($responseBody, true);
                    
                    if ($name === 'v1_calendar') {
                        // Single calendar endpoint
                        if (isset($data['id'])) {
                            $calendars[] = [
                                'id' => $data['id'],
                                'name' => $data['name'] ?? 'Default Calendar',
                                'endpoint' => $name
                            ];
                        }
                    } else {
                        // Multiple calendars endpoint
                        if (isset($data['value']) && is_array($data['value'])) {
                            foreach ($data['value'] as $calendar) {
                                $calendars[] = [
                                    'id' => $calendar['id'],
                                    'name' => $calendar['name'] ?? 'Unnamed Calendar',
                                    'endpoint' => $name
                                ];
                            }
                        }
                    }
                    
                    // If we found calendars, break out of the loop
                    if (!empty($calendars)) {
                        break;
                    }
                }
            }
            
            // Update the integration with the results
            $config = $integration->getConfig() ?: [];
            $config['calendars'] = $calendars;
            $config['calendar_api_results'] = $results;
            $integration->setConfig($config);
            
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            return $calendars;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to fetch calendars: ' . $e->getMessage());
        }
    }

    /**
     * Sync calendar events for a specific date range
     */
    public function syncEvents(IntegrationEntity $integration, DateTime $startDate, DateTime $endDate): array
    {
        try {
            $user = $integration->getUser();
            $graph = $this->getGraphClient($integration);
            
            // Start a database transaction
            $this->entityManager->beginTransaction();
            $savedEvents = [];
            $allEventIds = [];
            
            // Format dates for Microsoft Graph API (ISO 8601)
            $timeMin = $startDate->format('c');
            $timeMax = $endDate->format('c');
            
            // Get user's calendars
            $calendars = $graph->me()->calendars()->get()->wait();
            
            foreach ($calendars->getValue() as $calendar) {
                $calendarId = $calendar->getId();
                $calendarName = $calendar->getName();
                $calendarEventIds = [];
                
                // Skip some calendars based on criteria if needed
                if (false) { // Add your own criteria here
                    continue;
                }
                
                // Query for events in this calendar
                try {
                    $queryParams = [
                        'startDateTime' => $timeMin,
                        'endDateTime' => $timeMax
                    ];
                    
                    $events = $graph->me()->calendars()->byCalendarId($calendarId)->calendarView()
                        ->get($queryParams)->wait();
                    
                    $this->logger->info('Retrieved Outlook events', [
                        'calendar_id' => $calendarId,
                        'calendar_name' => $calendarName,
                        'count' => count($events->getValue())
                    ]);
                    
                    foreach ($events->getValue() as $event) {
                        // Skip Skedi events (implement detection criteria)
                        if ($this->isSkediEvent($event)) {
                            $calendarEventIds[] = $event->getId();
                            continue;
                        }
                        
                        // Save the event to our database
                        $savedEvent = $this->saveEvent($integration, $user, $event, $calendarId, $calendarName);
                        if ($savedEvent) {
                            $savedEvents[] = $savedEvent;
                            $calendarEventIds[] = $event->getId();
                        }
                    }
                } catch (\Exception $calendarException) {
                    $this->logger->error('Error fetching events for calendar: ' . $calendarException->getMessage(), [
                        'calendar_id' => $calendarId,
                        'calendar_name' => $calendarName
                    ]);
                    // Continue with next calendar
                    continue;
                }
                
                // Clean up deleted events for this calendar
                $this->cleanupDeletedEvents($user, $calendarEventIds, $calendarId, $startDate, $endDate);
                
                // Collect all event IDs
                $allEventIds = array_merge($allEventIds, $calendarEventIds);
            }
            
            // Update last synced timestamp
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // Commit the transaction
            $this->entityManager->commit();
            
            // Update user availability records
            $this->syncUserAvailability($user, $savedEvents);
            
            return $savedEvents;
        } catch (\Exception $e) {
            // Rollback transaction on error
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }
            
            $this->logger->error('Error syncing events: ' . $e->getMessage());
            throw new IntegrationException('Failed to sync calendar events: ' . $e->getMessage());
        }
    }

    /**
     * Save single Outlook Calendar event to database
     */
    private function saveEvent(
        IntegrationEntity $integration, 
        UserEntity $user, 
        $event, // Using a generic type since this is a model from the SDK
        string $calendarId, 
        string $calendarName
    ): ?OutlookCalendarEventEntity {
        try {
            // Check if the event already exists in our database
            $existingEvent = $this->entityManager->getRepository(OutlookCalendarEventEntity::class)->findOneBy([
                'user' => $user,
                'outlookEventId' => $event->getId(),
                'calendarId' => $calendarId
            ]);
            
            // Determine if this is an all-day event and set start/end times
            $isAllDay = $event->getIsAllDay() ?? false;
            $startTime = null;
            $endTime = null;
            
            $start = $event->getStart();
            $end = $event->getEnd();
            
            if ($isAllDay) {
                // All-day event
                $startTime = new DateTime($start->getDateTime(), new \DateTimeZone($start->getTimeZone() ?: 'UTC'));
                $endTime = new DateTime($end->getDateTime(), new \DateTimeZone($end->getTimeZone() ?: 'UTC'));
            } else {
                // Timed event - Convert to UTC
                $startDateTime = $start->getDateTime();
                $endDateTime = $end->getDateTime();
                $timezone = $start->getTimeZone() ?: 'UTC';
                
                $startTime = new DateTime($startDateTime, new \DateTimeZone($timezone));
                $endTime = new DateTime($endDateTime, new \DateTimeZone($timezone));
                
                $startTime->setTimezone(new \DateTimeZone('UTC'));
                $endTime->setTimezone(new \DateTimeZone('UTC'));
            }
            
            // Handle status mapping
            $status = 'confirmed';
            if ($event->getIsCancelled()) {
                $status = 'cancelled';
            }
            
            // Handle transparency mapping (free/busy)
            $transparency = $event->getShowAs();
            if ($transparency === 'free') {
                $transparency = 'transparent';
            } else {
                $transparency = 'opaque';
            }
            
            // Create or update the event
            if ($existingEvent) {
                // Update existing event
                $existingEvent->setTitle($event->getSubject() ?: 'Untitled Event');
                $existingEvent->setDescription($event->getBodyPreview());
                $existingEvent->setLocation($event->getLocation() ? $event->getLocation()->getDisplayName() : null);
                $existingEvent->setStartTime($startTime);
                $existingEvent->setEndTime($endTime);
                $existingEvent->setIsAllDay($isAllDay);
                $existingEvent->setStatus($status);
                $existingEvent->setTransparency($transparency);
                $existingEvent->setCalendarName($calendarName);
                
                // Handle organizer info
                if ($event->getOrganizer() && $event->getOrganizer()->getEmailAddress()) {
                    $existingEvent->setOrganizerEmail($event->getOrganizer()->getEmailAddress()->getAddress());
                    
                    // Determine if user is organizer
                    $userEmail = $integration->getConfig()['outlook_email'] ?? $user->getEmail();
                    $existingEvent->setIsOrganizer(
                        $event->getOrganizer()->getEmailAddress()->getAddress() === $userEmail
                    );
                }
                
                $existingEvent->setSyncedAt(new DateTime());
                
                $this->entityManager->persist($existingEvent);
                
                return $existingEvent;
            } else {
                // Create new event
                $newEvent = new OutlookCalendarEventEntity();
                $newEvent->setUser($user);
                $newEvent->setIntegration($integration);
                $newEvent->setOutlookEventId($event->getId());
                $newEvent->setCalendarId($calendarId);
                $newEvent->setCalendarName($calendarName);
                $newEvent->setTitle($event->getSubject() ?: 'Untitled Event');
                $newEvent->setDescription($event->getBodyPreview());
                $newEvent->setLocation($event->getLocation() ? $event->getLocation()->getDisplayName() : null);
                $newEvent->setStartTime($startTime);
                $newEvent->setEndTime($endTime);
                $newEvent->setIsAllDay($isAllDay);
                $newEvent->setStatus($status);
                $newEvent->setTransparency($transparency);
                
                // Handle organizer info
                if ($event->getOrganizer() && $event->getOrganizer()->getEmailAddress()) {
                    $newEvent->setOrganizerEmail($event->getOrganizer()->getEmailAddress()->getAddress());
                    
                    // Determine if user is organizer
                    $userEmail = $integration->getConfig()['outlook_email'] ?? $user->getEmail();
                    $newEvent->setIsOrganizer(
                        $event->getOrganizer()->getEmailAddress()->getAddress() === $userEmail
                    );
                }
                
                $newEvent->setSyncedAt(new DateTime());
                
                $this->entityManager->persist($newEvent);
                
                return $newEvent;
            }
        } catch (\Exception $e) {
            $this->logger->error('Error saving event: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean up events that no longer exist in Outlook Calendar
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
                OutlookCalendarEventEntity::class,
                $filters,
                1,
                1000,
                ['user' => $user]
            );
            
            foreach ($events as $event) {
                if (!in_array($event->getOutlookEventId(), $keepEventIds)) {
                    // Mark as cancelled
                    $event->setStatus('cancelled');
                    $this->entityManager->persist($event);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error cleaning up deleted events: ' . $e->getMessage());
        }
    }

    /**
     * Get events for a user within a date range using CrudManager
     */
    public function getEventsForDateRange(UserEntity $user, DateTime $startDate, DateTime $endDate): array
    {

        try {

            $filters = [
                [
                    'field' => 'startTime',
                    'operator' => 'less_than',
                    'value' => $endDate
                ],
                [
                    'field' => 'endTime',
                    'operator' => 'greater_than',
                    'value' => $startDate
                ],
                [
                    'field' => 'status',
                    'operator' => 'not_equals',
                    'value' => 'cancelled'
                ]
            ];
            
            return $this->crudManager->findMany(
                OutlookCalendarEventEntity::class,
                $filters,
                1,  // page
                1000, // limit
                ['user' => $user],
                function($queryBuilder) {
                    $queryBuilder->orderBy('t1.startTime', 'ASC');
                }
            );
        } catch (CrudException $e) {
            $this->logger->error('Error getting events: ' . $e->getMessage());
            return [];
        }
    }




    public function createCalendarEvent(
        IntegrationEntity $integration,
        string $title,
        DateTimeInterface $startTime,
        DateTimeInterface $endTime,
        array $options = []
    ): array {
        try {
            // Check token expiration explicitly
            if ($integration->getTokenExpires() && $integration->getTokenExpires() < new DateTime()) {
                try {
                    $this->refreshToken($integration);
                } catch (\Exception $e) {
                    throw new IntegrationException('Token refresh failed: ' . $e->getMessage());
                }
            }
            
            // Create event via direct API call for maximum reliability
            $client = new \GuzzleHttp\Client([
                'timeout' => 10, // Add timeout to prevent long-running requests
                'http_errors' => false // Don't throw exceptions for HTTP errors
            ]);
            
            // Create the event data
            $eventData = [
                'subject' => $title,
                'body' => [
                    'contentType' => 'HTML',
                    'content' => $options['description'] ?? ''
                ],
                'start' => [
                    'dateTime' => $startTime->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC'
                ],
                'end' => [
                    'dateTime' => $endTime->format('Y-m-d\TH:i:s'),
                    'timeZone' => 'UTC'
                ]
            ];
            
            // Default to primary calendar (no ID)
            $endpoint = "https://graph.microsoft.com/v1.0/me/events";
            
            // Make the API call
            $response = $client->post($endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $integration->getAccessToken(),
                    'Content-Type' => 'application/json'
                ],
                'json' => $eventData
            ]);
            
            $statusCode = $response->getStatusCode();
            
            if ($statusCode !== 201 && $statusCode !== 200) {
                throw new IntegrationException(
                    "Failed to create event. Status: {$statusCode}, Response: " . $response->getBody()->getContents()
                );
            }
            
            $responseBody = json_decode($response->getBody()->getContents(), true);
            
            // Update integration's last synced time
            $integration->setLastSynced(new DateTime());
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
            
            // Return event data
            return [
                'outlook_event_id' => $responseBody['id'] ?? null,
                'calendar_id' => null, // We're using the default calendar
                'web_link' => $responseBody['webLink'] ?? null,
                'title' => $title,
                'start_time' => $startTime->format('Y-m-d H:i:s'),
                'end_time' => $endTime->format('Y-m-d H:i:s')
            ];
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to create Outlook Calendar event: ' . $e->getMessage());
        }
    }

    /**
     * Delete event from Outlook Calendar
     */
    public function deleteEvent(IntegrationEntity $integration, string $eventId): bool
    {
        try {
            $graph = $this->getGraphClient($integration);
            
            // Delete event using the v2 SDK
            $graph->me()->events()->byEventId($eventId)->delete()->wait();
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error deleting event: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Determine if an event is created by Skedi
     */
    private function isSkediEvent($event): bool
    {
        // Check for Skedi-specific markers in the description
        $description = $event->getBodyPreview() ?? '';
        if (strpos($description, 'Booking for:') !== false || 
            strpos($description, 'Booking details:') !== false) {
            return true;
        }
        
        // Check for Skedi-specific formats in the title
        $title = $event->getSubject() ?? '';
        if (strpos($title, ' - Booking') !== false) {
            return true;
        }
        
        // In a real implementation, we would check extended properties
        // but that requires additional graph queries
        
        return false;
    }

    /**
     * Sync user availability records from Outlook Calendar events
     */
    private function syncUserAvailability(UserEntity $user, array $events): void
    {
        try {
            foreach ($events as $event) {
                // Skip cancelled events or transparent events
                if ($event->getStatus() === 'cancelled' || $event->getTransparency() === 'transparent') {
                    continue;
                }
                
                // Create a source ID that uniquely identifies this event
                $sourceId = 'outlook_' . $event->getCalendarId() . '_' . $event->getOutlookEventId();
                
                // Use the availability service to create/update availability
                $this->userAvailabilityService->createExternalAvailability(
                    $user,
                    $event->getTitle() ?: 'Busy',
                    $event->getStartTime(),
                    $event->getEndTime(),
                    'outlook_calendar',
                    $sourceId,
                    $event->getDescription(),
                    $event->getStatus()
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Error syncing user availability: ' . $e->getMessage());
        }
    }



    private function getRecommendations(string $token_status, array $endpointResults): array
    {
        $recommendations = [];
        
        switch ($token_status) {
            case 'invalid':
                $recommendations[] = "Your token appears to be invalid. Try reconnecting your Outlook account.";
                break;
                
            case 'missing_calendar_permissions':
                $recommendations[] = "You have a valid token, but it's missing calendar permissions. Try reconnecting and ensuring you grant calendar access.";
                $recommendations[] = "Check if there are any Conditional Access policies in your organization that might be blocking calendar access.";
                break;
                
            case 'valid':
                if (!$endpointResults['calendars']['success']) {
                    $recommendations[] = "Some endpoints are working but the calendars endpoint is failing. Try using the default calendar instead.";
                } else {
                    $recommendations[] = "Your connection is working properly.";
                }
                break;
        }
        
        return $recommendations;
    }

}