<?php
// src/Plugins/Integrations/MessageHandler/SyncGoogleCalendarMessageHandler.php

namespace App\Plugins\Integrations\Google\Calendar\MessageHandler;


use App\Plugins\Integrations\Google\Calendar\Message\SyncGoogleCalendarMessage;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Google\Calendar\Service\GoogleCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use DateTime;

class SyncGoogleCalendarMessageHandler implements MessageHandlerInterface
{
    private IntegrationRepository $integrationRepository;
    private GoogleCalendarService $googleCalendarService;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    
    public function __construct(
        IntegrationRepository $integrationRepository,
        GoogleCalendarService $googleCalendarService,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->integrationRepository = $integrationRepository;
        $this->googleCalendarService = $googleCalendarService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }
    
    public function __invoke(SyncGoogleCalendarMessage $message)
    {
        $integration = $this->integrationRepository->find($message->getIntegrationId());
        
        if (!$integration || $integration->getProvider() !== 'google_calendar' || $integration->getStatus() !== 'active') {
            $this->logger->warning('Cannot sync: Invalid integration', [
                'integration_id' => $message->getIntegrationId()
            ]);
            return;
        }
        
        try {
            $startDate = new DateTime($message->getStartDate());
            $endDate = new DateTime($message->getEndDate());
            
            $events = $this->googleCalendarService->syncEvents($integration, $startDate, $endDate);
            
            $this->logger->info('Successfully synced Google Calendar', [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'events_count' => count($events)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error syncing Google Calendar: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}