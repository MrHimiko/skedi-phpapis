<?php

namespace App\Plugins\Integrations\Microsoft\Outlook\MessageHandler;

use App\Plugins\Integrations\Microsoft\Outlook\Message\SyncOutlookCalendarMessage;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Microsoft\Outlook\Service\OutlookCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use DateTime;

class SyncOutlookCalendarMessageHandler implements MessageHandlerInterface
{
    private IntegrationRepository $integrationRepository;
    private OutlookCalendarService $outlookCalendarService;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    
    public function __construct(
        IntegrationRepository $integrationRepository,
        OutlookCalendarService $outlookCalendarService,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger
    ) {
        $this->integrationRepository = $integrationRepository;
        $this->outlookCalendarService = $outlookCalendarService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }
    
    public function __invoke(SyncOutlookCalendarMessage $message)
    {
        $integration = $this->integrationRepository->find($message->getIntegrationId());
        
        if (!$integration || $integration->getProvider() !== 'outlook_calendar' || $integration->getStatus() !== 'active') {
            $this->logger->warning('Cannot sync: Invalid integration', [
                'integration_id' => $message->getIntegrationId()
            ]);
            return;
        }
        
        try {
            $startDate = new DateTime($message->getStartDate());
            $endDate = new DateTime($message->getEndDate());
            
            $events = $this->outlookCalendarService->syncEvents($integration, $startDate, $endDate);
            
            $this->logger->info('Successfully synced Outlook Calendar', [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'events_count' => count($events)
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error syncing Outlook Calendar: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}