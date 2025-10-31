<?php
// src/Plugins/Integrations/Common/Command/DebugTokensCommand.php

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
use App\Plugins\Account\Repository\UserRepository;
use DateTime;

#[AsCommand(
    name: 'app:debug-integration-tokens',
    description: 'Debug Google integration tokens - analyze health, expiration, and OAuth configuration',
)]
class DebugTokensCommand extends Command
{
    private IntegrationRepository $integrationRepository;
    private UserRepository $userRepository;
    private GoogleCalendarService $googleCalendarService;
    private GoogleMeetService $googleMeetService;
    
    public function __construct(
        IntegrationRepository $integrationRepository,
        UserRepository $userRepository,
        GoogleCalendarService $googleCalendarService,
        GoogleMeetService $googleMeetService
    ) {
        $this->integrationRepository = $integrationRepository;
        $this->userRepository = $userRepository;
        $this->googleCalendarService = $googleCalendarService;
        $this->googleMeetService = $googleMeetService;
        
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'user-id',
                'u',
                InputOption::VALUE_OPTIONAL,
                'Debug tokens for specific user ID'
            )
            ->addOption(
                'integration-id',
                'i',
                InputOption::VALUE_OPTIONAL,
                'Debug specific integration ID'
            )
            ->addOption(
                'provider',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Filter by provider (google_calendar, google_meet)'
            )
            ->addOption(
                'test-auth-url',
                't',
                InputOption::VALUE_NONE,
                'Test OAuth authorization URL generation'
            )
            ->addOption(
                'expired-only',
                null,  // Remove the 'e' shortcut that's causing conflict
                InputOption::VALUE_NONE,
                'Only show expired or expiring tokens'
            )
            ->addOption(
                'detailed',
                'd',
                InputOption::VALUE_NONE,
                'Show detailed token information (including partial tokens for debugging)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userId = $input->getOption('user-id');
        $integrationId = $input->getOption('integration-id');
        $provider = $input->getOption('provider');
        $testAuthUrl = $input->getOption('test-auth-url');
        $expiredOnly = $input->getOption('expired-only');
        $detailed = $input->getOption('detailed');
        
        $io->title('Google Integration Token Debug Tool');
        
        // Test OAuth URL generation if requested
        if ($testAuthUrl) {
            $this->testAuthUrlGeneration($io);
            return Command::SUCCESS;
        }
        
        // Build query criteria
        $criteria = [];
        if ($provider) {
            $criteria['provider'] = $provider;
        }
        
        if ($integrationId) {
            $integration = $this->integrationRepository->find($integrationId);
            if (!$integration) {
                $io->error("Integration with ID {$integrationId} not found");
                return Command::FAILURE;
            }
            $integrations = [$integration];
        } elseif ($userId) {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                $io->error("User with ID {$userId} not found");
                return Command::FAILURE;
            }
            $criteria['user'] = $user;
            $integrations = $this->integrationRepository->findBy($criteria, ['created' => 'DESC']);
        } else {
            $integrations = $this->integrationRepository->findBy($criteria, ['created' => 'DESC']);
        }
        
        if (empty($integrations)) {
            $io->warning('No integrations found matching criteria');
            return Command::SUCCESS;
        }
        
        // Filter by expiration status if requested
        if ($expiredOnly) {
            $now = new DateTime();
            $integrations = array_filter($integrations, function($integration) use ($now) {
                $expires = $integration->getTokenExpires();
                return !$expires || $expires <= $now || $expires <= new DateTime('+1 hour');
            });
            
            if (empty($integrations)) {
                $io->success('No expired or expiring tokens found');
                return Command::SUCCESS;
            }
        }
        
        $io->info("Analyzing " . count($integrations) . " integrations...");
        
        // Analyze each integration
        foreach ($integrations as $integration) {
            $this->analyzeIntegration($io, $integration, $detailed);
            $io->newLine();
        }
        
        return Command::SUCCESS;
    }
    
    private function testAuthUrlGeneration(SymfonyStyle $io): void
    {
        $io->section('Testing OAuth Authorization URL Generation');
        
        try {
            // Test Google Calendar
            $io->writeln('<info>Testing Google Calendar Auth URL:</info>');
            $calendarAuthUrl = $this->googleCalendarService->getAuthUrl();
            $io->writeln("Generated URL: {$calendarAuthUrl}");
            
            $this->validateAuthUrl($io, $calendarAuthUrl, 'Google Calendar');
            
            $io->newLine();
            
            // Test Google Meet
            $io->writeln('<info>Testing Google Meet Auth URL:</info>');
            $meetAuthUrl = $this->googleMeetService->getAuthUrl();
            $io->writeln("Generated URL: {$meetAuthUrl}");
            
            $this->validateAuthUrl($io, $meetAuthUrl, 'Google Meet');
            
        } catch (\Exception $e) {
            $io->error('Failed to generate auth URLs: ' . $e->getMessage());
            $io->writeln('Error details: ' . $e->getTraceAsString());
        }
    }
    
    private function validateAuthUrl(SymfonyStyle $io, string $url, string $service): void
    {
        $requiredParams = ['access_type', 'prompt', 'client_id', 'redirect_uri', 'response_type', 'scope'];
        $criticalValues = [
            'access_type' => 'offline',
            'prompt' => 'consent',
            'response_type' => 'code'
        ];
        
        $urlParts = parse_url($url);
        if (!isset($urlParts['query'])) {
            $io->error('No query parameters found in URL');
            return;
        }
        
        parse_str($urlParts['query'], $params);
        
        $io->writeln("<comment>Validating {$service} auth URL parameters:</comment>");
        
        foreach ($requiredParams as $param) {
            if (isset($params[$param])) {
                $value = $params[$param];
                
                if (isset($criticalValues[$param])) {
                    $expected = $criticalValues[$param];
                    if ($value === $expected) {
                        $io->writeln("✅ {$param}: {$value}");
                    } else {
                        $io->writeln("❌ {$param}: {$value} (expected: {$expected})");
                    }
                } else {
                    $io->writeln("✅ {$param}: {$value}");
                }
            } else {
                $io->writeln("❌ {$param}: MISSING");
            }
        }
    }
    
    private function analyzeIntegration(SymfonyStyle $io, $integration, bool $detailed): void
    {
        $provider = $integration->getProvider();
        $user = $integration->getUser();
        
        $io->section("Integration ID: {$integration->getId()} ({$provider})");
        
        // Basic integration info
        $io->definitionList(
            ['User ID' => $user->getId()],
            ['User Email' => $user->getEmail()],
            ['Provider' => $provider],
            ['Status' => $integration->getStatus()],
            ['Created' => $integration->getCreated()->format('Y-m-d H:i:s')],
            ['Last Synced' => $integration->getLastSynced() ? $integration->getLastSynced()->format('Y-m-d H:i:s') : 'Never']
        );
    }
}