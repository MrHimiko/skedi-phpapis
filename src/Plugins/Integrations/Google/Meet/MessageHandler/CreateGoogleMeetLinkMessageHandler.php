<?php

namespace App\Plugins\Integrations\Google\Meet\MessageHandler;

use App\Plugins\Integrations\Google\Meet\Message\CreateGoogleMeetLinkMessage;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Google\Meet\Service\GoogleMeetService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use DateTime;

class CreateGoogleMeetLinkMessageHandler implements MessageHandlerInterface
{
    private IntegrationRepository $integrationRepository;
    private GoogleMeetService $googleMeetService;
    private EntityManagerInterface $entityManager;
    
    public function __construct(
        IntegrationRepository $integrationRepository,
        GoogleMeetService $googleMeetService,
        EntityManagerInterface $entityManager
    ) {
        $this->integrationRepository = $integrationRepository;
        $this->googleMeetService = $googleMeetService;
        $this->entityManager = $entityManager;
    }
    
    public function __invoke(CreateGoogleMeetLinkMessage $message)
    {
        try {
            $integration = $this->integrationRepository->find($message->getIntegrationId());
            
            if (!$integration || $integration->getStatus() !== 'active') {
                return;
            }
            
            $startTime = new DateTime($message->getStartTime());
            $endTime = new DateTime($message->getEndTime());
            
            $this->googleMeetService->createMeetLink(
                $integration,
                $message->getTitle(),
                $startTime,
                $endTime,
                $message->getEventId(),
                $message->getBookingId(),
                $message->getOptions()
            );
        } catch (\Exception $e) {
            // Silently fail in async processing
        }
    }
}