<?php
// src/Plugins/Integrations/Common/Command/RefreshTokensCommand.php

namespace App\Plugins\Integrations\Common\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Google\Calendar\Service\GoogleCalendarService;
use App\Plugins\Integrations\Google\Meet\Service\GoogleMeetService;
use Psr\Log\LoggerInterface;
use DateTime;

#[AsCommand(
    name: 'app:refresh-integration-tokens',
    description: 'Proactively refresh expiring Google integration tokens to prevent authentication failures',
)]
class RefreshTokensCommand extends Command
{
    private IntegrationRepository $integrationRepository;
    private GoogleCalendarService $googleCalendarService;
    private GoogleMeetService $googleMeetService;
    private LoggerInterface $logger;
    
    public function __construct(
        IntegrationRepository $integrationRepository,
        GoogleCalendarService $googleCalendarService,
        GoogleMeetService $googleMeetService,
        LoggerInterface $logger
    ) {
        $this->integrationRepository = $integrationRepository;
        $this->googleCalendarService = $googleCalendarService;
        $this->googleMeetService = $googleMeetService;
        $this->logger = $logger;
        
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'hours-ahead', 
                null,  // Remove the 'h' shortcut to avoid conflicts
                InputOption::VALUE_OPTIONAL, 
                'Refresh tokens that expire within this many hours (default: 2)', 
                2
            )
            ->addOption(
                'dry-run', 
                'd', 
                InputOption::VALUE_NONE, 
                'Show what would be refreshed without actually refreshing tokens'
            )
            ->addOption(
                'provider', 
                'p', 
                InputOption::VALUE_OPTIONAL, 
                'Only refresh tokens for specific provider (google_calendar, google_meet)', 
                null
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hoursAhead = (int) $input->getOption('hours-ahead');
        $isDryRun = $input->getOption('dry-run');
        $providerFilter = $input->getOption('provider');
        
        $io->title('Proactive Token Refresh for Google Integrations');
        
        if ($isDryRun) {
            $io->note('DRY RUN MODE - No tokens will actually be refreshed');
        }
        
        // Calculate threshold for token expiration
        $expirationThreshold = new DateTime("+{$hoursAhead} hours");
        
        $io->info("Looking for tokens that expire before: " . $expirationThreshold->format('Y-m-d H:i:s'));
        
        // Build criteria for finding expiring integrations
        $criteria = [
            'status' => 'active'
        ];
        
        if ($providerFilter) {
            $criteria['provider'] = $providerFilter;
        }
        
        // Get all active Google integrations
        $integrations = $this->integrationRepository->findBy($criteria);
        
        if (empty($integrations)) {
            $io->warning('No active integrations found');
            return Command::SUCCESS;
        }
        
        $io->info("Found " . count($integrations) . " active integrations to check");
        
        // Filter to only those that need refresh
        $integrationsNeedingRefresh = [];
        foreach ($integrations as $integration) {
            // Only check Google integrations
            if (!in_array($integration->getProvider(), ['google_calendar', 'google_meet'])) {
                continue;
            }
            
            $tokenExpires = $integration->getTokenExpires();
            
            // Include integrations that:
            // 1. Have no expiration time set (should not happen but let's be safe)
            // 2. Expire before our threshold
            if (!$tokenExpires || $tokenExpires <= $expirationThreshold) {
                $integrationsNeedingRefresh[] = $integration;
            }
        }
        
        if (empty($integrationsNeedingRefresh)) {
            $io->success('All tokens are fresh - no refresh needed');
            return Command::SUCCESS;
        }
        
        $io->warning("Found " . count($integrationsNeedingRefresh) . " integrations with expiring tokens");
        
        // Show details of what will be refreshed
        $headers = ['ID', 'User Email', 'Provider', 'Current Expires', 'Hours Until Expiry', 'Has Refresh Token'];
        $rows = [];
        
        foreach ($integrationsNeedingRefresh as $integration) {
            $userEmail = $integration->getUser()->getEmail();
            $provider = $integration->getProvider();
            $expires = $integration->getTokenExpires();
            $expiresStr = $expires ? $expires->format('Y-m-d H:i:s') : 'Never set';
            
            $hoursUntilExpiry = 'N/A';
            if ($expires) {
                $diff = (new DateTime())->diff($expires);
                $totalHours = ($diff->days * 24) + $diff->h;
                $hoursUntilExpiry = $diff->invert ? "Expired {$totalHours}h ago" : "{$totalHours}h {$diff->i}m";
            }
            
            $hasRefreshToken = $integration->getRefreshToken() ? 'Yes' : 'No';
            
            $rows[] = [
                $integration->getId(),
                $userEmail,
                $provider,
                $expiresStr,
                $hoursUntilExpiry,
                $hasRefreshToken
            ];
        }
        
        $io->table($headers, $rows);
        
        if ($isDryRun) {
            $io->note('Dry run complete - no tokens were actually refreshed');
            return Command::SUCCESS;
        }
        
        // Proceed with actual refresh
        $io->progressStart(count($integrationsNeedingRefresh));
        
        $stats = [
            'success' => 0,
            'failed' => 0,
            'no_refresh_token' => 0,
            'already_fresh' => 0
        ];
        
        foreach ($integrationsNeedingRefresh as $integration) {
            try {
                $provider = $integration->getProvider();
                $userId = $integration->getUser()->getId();
                
                // Check if refresh token exists
                if (!$integration->getRefreshToken()) {
                    $io->warning("Integration {$integration->getId()} has no refresh token - marking as expired");
                    
                    $integration->setStatus('expired');
                    $this->integrationRepository->getEntityManager()->persist($integration);
                    $this->integrationRepository->getEntityManager()->flush();
                    
                    $stats['no_refresh_token']++;
                    $io->progressAdvance();
                    continue;
                }
                
                // Refresh based on provider
                if ($provider === 'google_calendar') {
                    $this->refreshCalendarToken($integration);
                } elseif ($provider === 'google_meet') {
                    $this->refreshMeetToken($integration);
                } else {
                    $io->warning("Unknown provider: {$provider}");
                    $stats['failed']++;
                    $io->progressAdvance();
                    continue;
                }
                
                $this->logger->info('Successfully refreshed token via cron', [
                    'integration_id' => $integration->getId(),
                    'user_id' => $userId,
                    'provider' => $provider
                ]);
                
                $stats['success']++;
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to refresh token via cron', [
                    'integration_id' => $integration->getId(),
                    'user_id' => $integration->getUser()->getId(),
                    'provider' => $integration->getProvider(),
                    'error' => $e->getMessage()
                ]);
                
                // Check if this is a permanent failure requiring re-auth
                if (strpos($e->getMessage(), 'invalid_grant') !== false ||
                    strpos($e->getMessage(), 'refresh token') !== false) {
                    
                    $integration->setStatus('expired');
                    $this->integrationRepository->getEntityManager()->persist($integration);
                    $this->integrationRepository->getEntityManager()->flush();
                    
                    $io->warning("Integration {$integration->getId()} marked as expired - user needs to reconnect");
                }
                
                $stats['failed']++;
            }
            
            $io->progressAdvance();
        }
        
        $io->progressFinish();
        
        // Display results
        $io->section('Refresh Results');
        $io->listing([
            "✅ Successfully refreshed: {$stats['success']}",
            "❌ Failed to refresh: {$stats['failed']}",
            "🔄 No refresh token (marked as expired): {$stats['no_refresh_token']}",
            "📊 Already fresh: {$stats['already_fresh']}"
        ]);
        
        if ($stats['success'] > 0) {
            $io->success("Successfully refreshed {$stats['success']} tokens");
        }
        
        if ($stats['failed'] > 0 || $stats['no_refresh_token'] > 0) {
            $io->warning("Some tokens could not be refreshed - affected users will need to reconnect");
        } else {
            $io->success('All tokens successfully refreshed!');
        }
        
        return Command::SUCCESS;
    }
    
    /**
     * Refresh Google Calendar token using the service
     */
    private function refreshCalendarToken($integration): void
    {
        // Use reflection to call the private refreshTokenIfNeeded method
        $reflection = new \ReflectionClass($this->googleCalendarService);
        $method = $reflection->getMethod('refreshTokenIfNeeded');
        $method->setAccessible(true);
        $method->invoke($this->googleCalendarService, $integration);
    }
    
    /**
     * Refresh Google Meet token using the service
     */
    private function refreshMeetToken($integration): void
    {
        // Use reflection to call the private refreshTokenIfNeeded method
        $reflection = new \ReflectionClass($this->googleMeetService);
        $method = $reflection->getMethod('refreshTokenIfNeeded');
        $method->setAccessible(true);
        $method->invoke($this->googleMeetService, $integration);
    }
}