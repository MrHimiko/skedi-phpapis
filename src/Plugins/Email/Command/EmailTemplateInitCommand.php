<?php

namespace App\Plugins\Email\Command;

use App\Plugins\Email\Service\EmailTemplateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:email:init-templates',
    description: 'Initialize default email templates',
)]
class EmailTemplateInitCommand extends Command
{
    private EmailTemplateService $templateService;
    
    public function __construct(EmailTemplateService $templateService)
    {
        parent::__construct();
        $this->templateService = $templateService;
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->info('Initializing default email templates...');
        
        try {
            $this->templateService->initializeDefaultTemplates();
            $io->success('Email templates initialized successfully!');
            
            $templates = $this->templateService->getActiveTemplates();
            $io->table(
                ['Name', 'Provider ID', 'Description'],
                array_map(fn($t) => [
                    $t->getName(),
                    $t->getProviderId(),
                    $t->getDescription()
                ], $templates)
            );
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error('Failed to initialize templates: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}