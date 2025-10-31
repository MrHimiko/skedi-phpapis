<?php
// src/Plugins/Integrations/Service/GoogleCalendarSyncService.php

namespace App\Plugins\Integrations\Google\Calendar\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Google\Calendar\Entity\GoogleCalendarEventEntity;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use DateTime;
use Psr\Log\LoggerInterface;

class GoogleCalendarSyncService
{
    private EntityManagerInterface $entityManager;
    private GoogleCalendarService $googleCalendarService;
    private LoggerInterface $logger;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        GoogleCalendarService $googleCalendarService,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->googleCalendarService = $googleCalendarService;
        $this->logger = $logger;
    }
    
    /**
     * Sync calendar events for a specific date range
     */
    public function syncEvents(IntegrationEntity $integration, DateTime $startDate, DateTime $endDate): array
    {
        // Simply delegate to the GoogleCalendarService
        return $this->googleCalendarService->syncEvents($integration, $startDate, $endDate);
    }
    
    /**
     * Sync calendar events for all users who haven't synced recently
     */
    public function syncAllUsers(int $hoursSinceLastSync = 1): array
    {
        $results = [
            'success' => 0,
            'failure' => 0,
            'skipped' => 0
        ];
        
        try {
            // Get all active Google Calendar integrations
            $integrations = $this->entityManager->getRepository(IntegrationEntity::class)->findBy([
                'provider' => 'google_calendar',
                'status' => 'active'
            ]);
            
            $cutoffTime = new DateTime('-' . $hoursSinceLastSync . ' hours');
            
            foreach ($integrations as $integration) {
                try {
                    // Only sync calendars that haven't been synced in the specified time
                    $lastSynced = $integration->getLastSynced();
                    
                    if (!$lastSynced || $lastSynced < $cutoffTime) {
                        // Sync the next 14 days by default
                        $startDate = new DateTime('today');
                        $endDate = new DateTime('+14 days');
                        
                        $events = $this->googleCalendarService->syncEvents($integration, $startDate, $endDate);
                        
                        $this->logger->info('Synced Google Calendar events', [
                            'integration_id' => $integration->getId(),
                            'user_id' => $integration->getUser()->getId(),
                            'events_count' => count($events)
                        ]);
                        
                        $results['success']++;
                    } else {
                        $results['skipped']++;
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to sync calendar: ' . $e->getMessage(), [
                        'integration_id' => $integration->getId(),
                        'user_id' => $integration->getUser()->getId()
                    ]);
                    
                    $results['failure']++;
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error during batch sync: ' . $e->getMessage());
            throw $e;
        }
        
        return $results;
    }
}