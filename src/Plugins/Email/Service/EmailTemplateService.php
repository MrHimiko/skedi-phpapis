<?php
// Path: src/Plugins/Email/Service/EmailTemplateService.php

namespace App\Plugins\Email\Service;

use App\Plugins\Email\Entity\EmailTemplateEntity;
use App\Plugins\Email\Repository\EmailTemplateRepository;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;

class EmailTemplateService
{
    private EntityManagerInterface $entityManager;
    private EmailTemplateRepository $templateRepository;
    private CrudManager $crudManager;
    private array $templateCache = [];
    
    public function __construct(
        EntityManagerInterface $entityManager,
        EmailTemplateRepository $templateRepository,
        CrudManager $crudManager
    ) {
        $this->entityManager = $entityManager;
        $this->templateRepository = $templateRepository;
        $this->crudManager = $crudManager;
    }
    
    /**
     * Get template by name
     */
    public function getTemplate(string $name): ?EmailTemplateEntity
    {
        if (isset($this->templateCache[$name])) {
            return $this->templateCache[$name];
        }
        
        // Use repository directly since we need to find by name, not ID
        $template = $this->templateRepository->findOneBy(['name' => $name, 'active' => true]);
        
        if ($template) {
            $this->templateCache[$name] = $template;
        }
        
        return $template;
    }
    
    /**
     * Create or update template
     */
    public function upsertTemplate(
        string $name,
        string $providerId,
        string $description,
        array $defaultData = [],
        array $requiredFields = []
    ): EmailTemplateEntity {
        $template = $this->crudManager->getOne(
            EmailTemplateEntity::class,
            ['name' => $name]
        );
        
        if (!$template) {
            $template = new EmailTemplateEntity();
        }
        
        $templateData = [
            'name' => $name,
            'provider_id' => $providerId,
            'description' => $description,
            'default_data' => $defaultData,
            'required_fields' => $requiredFields,
            'active' => true
        ];
        
        if ($template->getId()) {
            $this->crudManager->update($template, $templateData);
        } else {
            $template = $this->crudManager->create(EmailTemplateEntity::class, $templateData);
        }
        
        // Clear cache
        unset($this->templateCache[$name]);
        
        return $template;
    }
    
    /**
     * Get all active templates
     */
    public function getActiveTemplates(): array
    {
        return $this->crudManager->getMany(
            EmailTemplateEntity::class,
            ['active' => true],
            1,
            100
        );
    }
    
    /**
     * Initialize default templates for Resend
     * Updated from SendGrid template IDs to Resend-compatible IDs
     */
    public function initializeDefaultTemplates(): void
    {
        $defaultTemplates = [
            [
                'name' => 'meeting_scheduled',
                // Changed from SendGrid ID (d-877ae9faa55c481db86b24fe1cfd0a62) to Resend format
                'provider_id' => 'meeting_scheduled', // Will use inline template in ResendProvider
                'description' => 'Sent when a meeting is scheduled',
                'default_data' => [
                    'meeting_name' => 'Meeting',
                    'location' => 'TBD'
                ],
                'required_fields' => ['meeting_name', 'date', 'tisme', 'duration']
            ],
            [
                'name' => 'meeting_scheduled_host',
                // Changed from SendGrid ID (d-f4fdf8f2e57f48e194f86d93c4cb72ee) to Resend format
                'provider_id' => 'meeting_scheduled_host', // Will use inline template
                'description' => 'Sent to host when a meeting is scheduled',
                'default_data' => [
                    'meeting_name' => 'Meeting',
                    'location' => 'TBD',
                    'guest_message' => ''
                ],
                'required_fields' => ['meeting_name', 'date', 'time', 'duration']
            ],
            [
                'name' => 'meeting_reminder',
                // Changed from SendGrid ID (d-83e3b63d86414549ab1c64522088d31f) to Resend format
                'provider_id' => 'meeting_reminder', // Will use inline template
                'description' => 'Sent reminders to both hosts & guests',
                'default_data' => [
                    'meeting_name' => 'Meeting',
                    'location' => 'TBD',
                    'guest_message' => ''
                ],
                'required_fields' => ['meeting_name', 'date', 'time', 'duration']
            ],
            [
                'name' => 'invitation',
                // Changed from SendGrid ID (d-256b80c62d7743dfa9fc5c6726856993) to Resend format
                'provider_id' => 'invitation', // Will use inline template
                'description' => 'Sent when user is invited to organization or team',
                'default_data' => [
                    'organization_name' => '',
                    'inviter_name' => ''
                ],
                'required_fields' => ['organization_name', 'email']
            ],
            [
                'name' => 'booking_created',
                'provider_id' => 'booking_created',
                'description' => 'Sent to host when a booking is created',
                'default_data' => [
                    'host_name' => '',
                    'guest_name' => '',
                    'event_name' => ''
                ],
                'required_fields' => ['host_name', 'guest_name', 'event_date', 'event_time']
            ],
            [
                'name' => 'booking_confirmed', 
                'provider_id' => 'booking_confirmed',
                'description' => 'Sent to guest when a booking is confirmed',
                'default_data' => [
                    'guest_name' => '',
                    'host_name' => '',
                    'event_name' => ''
                ],
                'required_fields' => ['guest_name', 'host_name', 'event_date', 'event_time']
            ],
            [
                'name' => 'blank',
                'provider_id' => 'blank',
                'description' => 'Blank template for WYSIWYG email campaigns',
                'default_data' => [
                    'content' => '',
                    'app_name' => 'Skedi',
                    'app_url' => 'https://skedi.com'
                ],
                'required_fields' => ['content']
            ],
            [
                'name' => 'meeting_cancelled',
                'provider_id' => 'meeting_cancelled',
                'description' => 'Sent when a meeting is cancelled',
                'default_data' => [
                    'meeting_name' => 'Meeting',
                    'cancellation_reason' => ''
                ],
                'required_fields' => ['meeting_name', 'date', 'time']
            ],
        ];
        
        foreach ($defaultTemplates as $templateData) {
            $this->upsertTemplate(
                $templateData['name'],
                $templateData['provider_id'],
                $templateData['description'],
                $templateData['default_data'],
                $templateData['required_fields']
            );
        }
    }
}

