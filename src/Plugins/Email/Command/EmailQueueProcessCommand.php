<?php

namespace App\Plugins\Email\Command;

use App\Plugins\Email\Service\EmailService;
use App\Plugins\Email\Service\EmailQueueService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'app:email:process-queue',
    description: 'Process pending emails in the queue',
)]
class EmailQueueProcessCommand extends Command
{
    private EmailService $emailService;
    private EmailQueueService $queueService;
    private LoggerInterface $logger;
    
    public function __construct(
        EmailService $emailService,
        EmailQueueService $queueService,
        LoggerInterface $logger
    ) {
        parent::__construct();
        $this->emailService = $emailService;
        $this->queueService = $queueService;
        $this->logger = $logger;
    }
    
    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of emails to process', 50)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Run without actually sending emails');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $dryRun = $input->getOption('dry-run');
        
        $io->info(sprintf('Processing email queue (limit: %d)...', $limit));
        
        $pendingEmails = $this->queueService->getPending($limit);
        $processed = 0;
        $sent = 0;
        $failed = 0;
        
        foreach ($pendingEmails as $queueItem) {
            $processed++;
            
            if ($dryRun) {
                $io->comment(sprintf('Would send email to: %s (template: %s)', 
                    $queueItem->getTo(), 
                    $queueItem->getTemplate()
                ));
                continue;
            }
            
            try {
                // Decode recipient if it's JSON (for multiple recipients)
                $to = json_decode($queueItem->getTo(), true) ?? $queueItem->getTo();
                
                // Send the email
                $result = $this->emailService->sendNow(
                    $to,
                    $queueItem->getTemplate(),
                    $queueItem->getData(),
                    $queueItem->getOptions()
                );
                
                // Mark as sent
                $this->queueService->markAsSent($queueItem, $result['message_id'] ?? null);
                $sent++;
                
                $io->success(sprintf('Sent email to: %s', $queueItem->getTo()));
                
            } catch (\Exception $e) {
                $failed++;
                $this->queueService->markAsFailed($queueItem, $e->getMessage());
                
                $io->error(sprintf('Failed to send email to %s: %s', 
                    $queueItem->getTo(), 
                    $e->getMessage()
                ));
                
                $this->logger->error('Email queue processing error', [
                    'queue_id' => $queueItem->getId(),
                    'to' => $queueItem->getTo(),
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $io->success(sprintf(
            'Processed %d emails: %d sent, %d failed',
            $processed,
            $sent,
            $failed
        ));
        
        return Command::SUCCESS;
    }
}