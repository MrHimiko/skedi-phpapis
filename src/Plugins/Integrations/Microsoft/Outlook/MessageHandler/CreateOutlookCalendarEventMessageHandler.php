<?php

namespace App\Plugins\Integrations\Microsoft\Outlook\MessageHandler;

use App\Plugins\Integrations\Microsoft\Outlook\Message\CreateOutlookCalendarEventMessage;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Microsoft\Outlook\Service\OutlookCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use DateTime;

class CreateOutlookCalendarEventMessageHandler implements MessageHandlerInterface
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
    ){
        $this->integrationRepository = $integrationRepository;
        $this->outlookCalendarService = $outlookCalendarService;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }
    
    public function __invoke(CreateOutlookCalendarEventMessage $message)
    {
        $integration = $this->integrationRepository->find($message->getIntegrationId());
        
        if (!$integration || $integration->getProvider() !== 'outlook_calendar' || $integration->getStatus() !== 'active') {
            $this->logger->warning('Cannot create event: Invalid integration', [
                'integration_id' => $message->getIntegrationId()
            ]);
            return;
        }
        
        try {
            $startTime = new DateTime($message->getStartTime());
            $endTime = new DateTime($message->getEndTime());
            
            $event = $this->outlookCalendarService->createCalendarEvent(
                $integration,
                $message->getTitle(),
                $startTime,
                $endTime,
                $message->getOptions()
            );
            
            $this->logger->info('Successfully created Outlook Calendar event', [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'event_id' => $event['outlook_event_id'] ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error creating Outlook Calendar event: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}