<?php
// src/Command/TestOAuthCommand.php

namespace App\Plugins\Integrations\Common\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Plugins\Integrations\Google\Calendar\Service\GoogleCalendarService;
use App\Plugins\Integrations\Google\Meet\Service\GoogleMeetService;

#[AsCommand(name: 'app:test-oauth-urls')]
class TestOAuthCommand extends Command
{
    private GoogleCalendarService $googleCalendarService;
    private GoogleMeetService $googleMeetService;
    
    public function __construct(
        GoogleCalendarService $googleCalendarService,
        GoogleMeetService $googleMeetService
    ) {
        $this->googleCalendarService = $googleCalendarService;
        $this->googleMeetService = $googleMeetService;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $io->title('Testing OAuth URL Generation');
            
            // Test Google Calendar
            $calendarUrl = $this->googleCalendarService->getAuthUrl();
            $io->success('Google Calendar URL generated successfully');
            $io->writeln('URL: ' . $calendarUrl);
            
            // Check for critical parameters
            if (strpos($calendarUrl, 'access_type=offline') !== false) {
                $io->writeln('✅ Contains access_type=offline');
            } else {
                $io->error('❌ Missing access_type=offline');
            }
            
            if (strpos($calendarUrl, 'prompt=consent') !== false) {
                $io->writeln('✅ Contains prompt=consent');
            } else {
                $io->error('❌ Missing prompt=consent');
            }
            
            $io->newLine();
            
            // Test Google Meet
            $meetUrl = $this->googleMeetService->getAuthUrl();
            $io->success('Google Meet URL generated successfully');
            $io->writeln('URL: ' . $meetUrl);
            
            // Check for critical parameters
            if (strpos($meetUrl, 'access_type=offline') !== false) {
                $io->writeln('✅ Contains access_type=offline');
            } else {
                $io->error('❌ Missing access_type=offline');
            }
            
            if (strpos($meetUrl, 'prompt=consent') !== false) {
                $io->writeln('✅ Contains prompt=consent');
            } else {
                $io->error('❌ Missing prompt=consent');
            }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Error: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}