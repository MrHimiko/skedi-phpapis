<?php

namespace App\Plugins\Integrations\Microsoft\Outlook\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Microsoft\Outlook\Service\OutlookCalendarService;
use Psr\Log\LoggerInterface;
use DateTime;

#[AsCommand(
    name: 'app:sync-outlook-calendars',
    description: 'Sync Outlook calendars for all users',
)]
class SyncOutlookCalendarsCommand extends Command
{
    private IntegrationRepository $integrationRepository;
    private OutlookCalendarService $outlookCalendarService;
    private LoggerInterface $logger;
    
    public function __construct(
        IntegrationRepository $integrationRepository,
        OutlookCalendarService $outlookCalendarService,
        LoggerInterface $logger
    ) {
        $this->integrationRepository = $integrationRepository;
        $this->outlookCalendarService = $outlookCalendarService;
        $this->logger = $logger;
        
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Syncing Outlook calendars for all users');
        
        // Get all active Outlook Calendar integrations
        $integrations = $this->integrationRepository->findBy([
            'provider' => 'outlook_calendar',
            'status' => 'active'
        ]);
        
        $io->progressStart(count($integrations));
        $successCount = 0;
        $failureCount = 0;
        $skippedCount = 0;
        
        foreach ($integrations as $integration) {
            try {
                // Only sync calendars that haven't been synced in the last hour
                $lastSynced = $integration->getLastSynced();
                $oneHourAgo = new DateTime('-1 hour');
                
                if (!$lastSynced || $lastSynced < $oneHourAgo) {
                    // Sync a reasonable date range - today to 30 days in future
                    $startDate = new DateTime('today');
                    $endDate = new DateTime('+30 days');
                    
                    // Also sync 7 days in the past for recent events
                    $pastStartDate = new DateTime('-7 days');
                    
                    // First sync recent past events
                    $this->outlookCalendarService->syncEvents($integration, $pastStartDate, $startDate);
                    
                    // Then sync future events
                    $events = $this->outlookCalendarService->syncEvents($integration, $startDate, $endDate);
                    
                    $this->logger->info('Synced Outlook Calendar events', [
                        'integration_id' => $integration->getId(),
                        'user_id' => $integration->getUser()->getId(),
                        'events_count' => count($events)
                    ]);
                    
                    $successCount++;
                } else {
                    $skippedCount++;
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to sync calendar: ' . $e->getMessage(), [
                    'integration_id' => $integration->getId(),
                    'user_id' => $integration->getUser()->getId()
                ]);
                
                $failureCount++;
            }
            
            $io->progressAdvance();
        }
        
        $io->progressFinish();
        
        $io->success(sprintf(
            'Synced %d calendars successfully. Skipped: %d. Failed: %d',
            $successCount,
            $skippedCount,
            $failureCount
        ));
        
        return Command::SUCCESS;
    }
}