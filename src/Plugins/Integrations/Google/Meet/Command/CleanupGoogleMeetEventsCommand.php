<?php


namespace App\Plugins\Integrations\Google\Meet\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Plugins\Integrations\Google\Meet\Service\GoogleMeetService;

#[AsCommand(
    name: 'app:cleanup-google-meet-events',
    description: 'Clean up expired Google Meet events',
)]
class CleanupGoogleMeetEventsCommand extends Command
{
    private GoogleMeetService $googleMeetService;
    
    public function __construct(GoogleMeetService $googleMeetService)
    {
        $this->googleMeetService = $googleMeetService;
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Number of days to retain Google Meet events', 7);
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $retentionDays = (int) $input->getOption('days');
        
        $io->title("Cleaning up Google Meet events older than {$retentionDays} days");
        
        try {
            $removedCount = $this->googleMeetService->cleanupExpiredMeetEvents($retentionDays);
            
            $io->success("Successfully removed {$removedCount} expired Google Meet events");
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error("Error cleaning up Google Meet events: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}