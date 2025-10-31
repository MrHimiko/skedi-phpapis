<?php

namespace App\Plugins\Billing\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use App\Plugins\Billing\Service\BillingService;
use App\Plugins\Email\Service\EmailService;
use App\Service\CrudManager;
use App\Plugins\Organizations\Entity\OrganizationEntity;
use App\Plugins\Organizations\Entity\UserOrganizationEntity;
use Psr\Log\LoggerInterface;

#[AsCommand(
    name: 'app:check-organization-compliance',
    description: 'Check organizations for seat limit compliance'
)]
class CheckOrganizationComplianceCommand extends Command
{
    public function __construct(
        private BillingService $billingService,
        private EmailService $emailService,
        private CrudManager $crudManager,
        private LoggerInterface $logger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        $io->title('Checking Organization Compliance');
        
        try {
            // Get non-compliant organizations
            $nonCompliant = $this->billingService->getNonCompliantOrganizations();
            
            if (empty($nonCompliant)) {
                $io->success('All organizations are compliant with their seat limits.');
                return Command::SUCCESS;
            }
            
            $io->warning(sprintf('Found %d non-compliant organizations', count($nonCompliant)));
            
            foreach ($nonCompliant as $item) {
                $organization = $item['organization'];
                $compliance = $item['compliance'];
                
                $io->section($organization->getName());
                $io->listing([
                    sprintf('Total seats: %d', $compliance['seat_info']['total']),
                    sprintf('Used seats: %d', $compliance['seat_info']['used']),
                    sprintf('Overage: %d seats', $compliance['overage_count']),
                ]);
                
                // Send warning email to organization admins
                $this->sendComplianceWarning($organization, $compliance);
                
                // Log compliance issue
                $this->logComplianceIssue($organization, $compliance);
            }
            
            $io->success('Compliance check completed. Warnings sent to non-compliant organizations.');
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Error during compliance check: ' . $e->getMessage());
            $this->logger->error('Compliance check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return Command::FAILURE;
        }
    }
    
    private function sendComplianceWarning(OrganizationEntity $organization, array $compliance): void
    {
        try {
            // Get organization admins using CrudManager
            $adminRelationships = $this->crudManager->findMany(
                UserOrganizationEntity::class,
                [],
                1,
                100,
                [
                    'organization' => $organization,
                    'role' => 'admin'
                ]
            );
            
            foreach ($adminRelationships as $relationship) {
                $admin = $relationship->getUser();
                
                // Send email warning
                $this->emailService->send(
                    $admin->getEmail(),
                    'compliance_warning',
                    [
                        'organization_name' => $organization->getName(),
                        'admin_name' => $admin->getName(),
                        'total_seats' => $compliance['seat_info']['total'],
                        'used_seats' => $compliance['seat_info']['used'],
                        'overage_count' => $compliance['overage_count'],
                        'required_seats' => $compliance['required_additional_seats'],
                        'billing_url' => $_ENV['APP_URL'] . '/organizations/' . $organization->getId() . '/billing'
                    ]
                );
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to send compliance warning', [
                'organization_id' => $organization->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
    
    private function logComplianceIssue(OrganizationEntity $organization, array $compliance): void
    {
        $this->logger->warning('Organization compliance issue detected', [
            'organization_id' => $organization->getId(),
            'organization_name' => $organization->getName(),
            'overage_count' => $compliance['overage_count'],
            'total_seats' => $compliance['seat_info']['total'],
            'used_seats' => $compliance['seat_info']['used']
        ]);
    }
}