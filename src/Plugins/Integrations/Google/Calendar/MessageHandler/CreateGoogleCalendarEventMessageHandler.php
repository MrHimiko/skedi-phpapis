<?php

namespace App\Plugins\Integrations\Google\Calendar\MessageHandler;

use App\Plugins\Integrations\Google\Calendar\Message\CreateGoogleCalendarEventMessage;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Google\Calendar\Service\GoogleCalendarService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use DateTime;

class CreateGoogleCalendarEventMessageHandler implements MessageHandlerInterface
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
    
    public function __invoke(CreateGoogleCalendarEventMessage $message)
    {
        $integration = $this->integrationRepository->find($message->getIntegrationId());
        
        if (!$integration || $integration->getProvider() !== 'google_calendar' || $integration->getStatus() !== 'active') {
            $this->logger->warning('Cannot create event: Invalid integration', [
                'integration_id' => $message->getIntegrationId()
            ]);
            return;
        }
        
        try {
            $startTime = new DateTime($message->getStartTime());
            $endTime = new DateTime($message->getEndTime());
            
            $event = $this->googleCalendarService->createCalendarEvent(
                $integration,
                $message->getTitle(),
                $startTime,
                $endTime,
                $message->getOptions()
            );
            
            $this->logger->info('Successfully created Google Calendar event', [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'event_id' => $event['google_event_id'] ?? 'unknown'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error creating Google Calendar event: ' . $e->getMessage(), [
                'integration_id' => $integration->getId(),
                'user_id' => $integration->getUser()->getId(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}