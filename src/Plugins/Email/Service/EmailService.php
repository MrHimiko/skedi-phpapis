<?php

namespace App\Plugins\Email\Service;

use App\Plugins\Email\Entity\EmailLogEntity;
use App\Plugins\Email\Exception\EmailException;
use App\Service\CrudManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class EmailService
{
    private EmailProviderInterface $provider;
    private EmailQueueService $queueService;
    private EmailTemplateService $templateService;
    private EmailLogService $logService;
    private CrudManager $crudManager;
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;
    private bool $queueByDefault;
    
    public function __construct(
        EmailProviderInterface $provider,
        EmailQueueService $queueService,
        EmailTemplateService $templateService,
        EmailLogService $logService,
        CrudManager $crudManager,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        bool $queueByDefault = true
    ) {
        $this->provider = $provider;
        $this->queueService = $queueService;
        $this->templateService = $templateService;
        $this->logService = $logService;
        $this->crudManager = $crudManager;
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->queueByDefault = $queueByDefault;
    }
    
    /**
     * Send or queue an email
     * 
     * @param string|array $to Recipient email(s)
     * @param string $templateName Internal template name
     * @param array $data Dynamic data for template
     * @param array $options Additional options
     * @return array Result of send or queue operation
     */
    public function send($to, string $templateName, array $data = [], array $options = []): array
    {
        // Validate template exists
        $template = $this->templateService->getTemplate($templateName);
        if (!$template) {
            // If template doesn't exist, create a basic one
            $template = new \stdClass();
            $template->provider_id = $templateName;
            $template->default_data = [];
        }
        
        // Merge template defaults with provided data
        if (method_exists($template, 'getDefaultData')) {
            $data = array_merge($template->getDefaultData(), $data);
        }
        
        // Add common data
        $data['app_name'] = $_ENV['APP_NAME'] ?? 'Skedi';
        $data['app_url'] = $_ENV['APP_URL'] ?? 'https://app.skedi.com';
        $data['current_year'] = date('Y');
        
        // ALWAYS QUEUE - fuck sending immediately
        return $this->queue($to, $templateName, $data, $options);
    }
    
    /**
     * Send email immediately - FIXED VERSION
     */
    public function sendNow($to, string $templateName, array $data = [], array $options = []): array
    {
        // Get template and handle null case properly
        $template = $this->templateService->getTemplate($templateName);
        
        // CRITICAL FIX: Handle null template case (same as send method)
        if (!$template) {
            $this->logger->warning("Template '{$templateName}' not found in database, creating fallback", [
                'template_name' => $templateName,
                'context' => 'sendNow'
            ]);
            
            // Create a basic fallback template object
            $template = new \stdClass();
            $template->provider_id = $templateName;
            $template->default_data = [];
            
            // Define a method for the stdClass object to avoid method_exists issues
            $template->getProviderId = function() use ($templateName) {
                return $templateName;
            };
        }
        
        // Create log entry manually
        $log = new EmailLogEntity();
        $log->setTo(is_array($to) ? implode(',', $to) : $to);
        $log->setTemplate($templateName);
        $log->setData($data);
        $log->setStatus('sending');
        $log->setProvider($this->provider->getName());
        
        $this->entityManager->persist($log);
        $this->entityManager->flush();
        
        try {
            // Get the provider ID safely
            $providerId = null;
            if (is_object($template)) {
                if (method_exists($template, 'getProviderId')) {
                    $providerId = $template->getProviderId();
                } elseif (property_exists($template, 'provider_id')) {
                    $providerId = $template->provider_id;
                } elseif (isset($template->getProviderId) && is_callable($template->getProviderId)) {
                    $providerId = call_user_func($template->getProviderId);
                }
            }
            
            // Final fallback - use template name as provider ID
            if (!$providerId) {
                $providerId = $templateName;
                $this->logger->warning("Could not determine provider ID for template, using template name as fallback", [
                    'template_name' => $templateName,
                    'provider_id_fallback' => $providerId
                ]);
            }
            
            // Send via provider
            $result = $this->provider->send(
                is_array($to) ? $to[0] : $to,
                $providerId,
                $data,
                array_merge($options, [
                    'cc' => is_array($to) && count($to) > 1 ? array_slice($to, 1) : null
                ])
            );
            
            // Update log
            $log->setStatus($result['success'] ? 'sent' : 'failed');
            $log->setMessageId($result['message_id'] ?? null);
            $log->setSentAt(new \DateTime());
            
            $this->entityManager->flush();
            
            return $result;
            
        } catch (\Exception $e) {
            // Update log on failure
            $log->setStatus('failed');
            $log->setError($e->getMessage());
            
            $this->entityManager->flush();
            
            // Log the error for debugging
            $this->logger->error('Failed to send email in sendNow', [
                'template_name' => $templateName,
                'to' => is_array($to) ? implode(',', $to) : $to,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Queue email for later sending
     */
    public function queue($to, string $templateName, array $data = [], array $options = []): array
    {
        $queueId = $this->queueService->add($to, $templateName, $data, $options);
        
        return [
            'success' => true,
            'queued' => true,
            'queue_id' => $queueId,
            'message' => 'Email queued successfully'
        ];
    }
    
    /**
     * Send bulk emails
     */
    public function sendBulk(array $recipients, string $templateName, array $globalData = [], array $options = []): array
    {
        $template = $this->templateService->getTemplate($templateName);
        if (!$template) {
            throw new EmailException("Template '{$templateName}' not found");
        }
        
        // Add common data
        $globalData['app_name'] = $_ENV['APP_NAME'] ?? 'Skedi';
        $globalData['app_url'] = $_ENV['APP_URL'] ?? 'https://app.skedi.com';
        $globalData['current_year'] = date('Y');
        
        return $this->provider->sendBulk($recipients, $template->getProviderId(), $globalData);
    }
    
    /**
     * Preview email template with data
     */
    public function preview(string $templateName, array $data = []): array
    {
        $template = $this->templateService->getTemplate($templateName);
        if (!$template) {
            throw new EmailException("Template '{$templateName}' not found");
        }
        
        // Merge with defaults
        $data = array_merge($template->getDefaultData(), $data);
        
        // Add common data
        $data['app_name'] = $_ENV['APP_NAME'] ?? 'Skedi';
        $data['app_url'] = $_ENV['APP_URL'] ?? 'https://app.skedi.com';
        $data['current_year'] = date('Y');
        
        return [
            'template' => $templateName,
            'provider_id' => $template->getProviderId(),
            'data' => $data,
            'description' => $template->getDescription(),
            'required_fields' => $template->getRequiredFields()
        ];
    }
}