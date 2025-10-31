<?php
// Path: src/Plugins/Email/Service/Providers/ResendProvider.php

namespace App\Plugins\Email\Service\Providers;

use App\Plugins\Email\Service\EmailProviderInterface;
use App\Plugins\Email\Templates\MeetingScheduledTemplate;
use App\Plugins\Email\Templates\MeetingScheduledHostTemplate;
use App\Plugins\Email\Templates\MeetingReminderTemplate;
use App\Plugins\Email\Templates\BookingCreatedTemplate;
use App\Plugins\Email\Templates\BookingConfirmedTemplate;
use App\Plugins\Email\Templates\InvitationTemplate;
use App\Plugins\Email\Templates\BlankTemplate;
use App\Plugins\Email\Templates\MeetingCancelledTemplate;
use App\Plugins\Email\Templates\PasswordResetTemplate;
use App\Plugins\Email\Templates\PasswordResetConfirmationTemplate;
use App\Plugins\Email\Templates\EmailVerificationTemplate;
use App\Plugins\Email\Templates\WelcomeTemplate;

use Psr\Log\LoggerInterface;

class ResendProvider implements EmailProviderInterface
{
    private ?string $apiKey;
    private $client;
    private LoggerInterface $logger;
    private array $templateMap;
    
    public function __construct(
        string $apiKey,
        LoggerInterface $logger,
        array $templateMap = []
    ) {
        $this->apiKey = $apiKey;
        $this->logger = $logger;
        $this->templateMap = $templateMap;
        $this->client = !empty($apiKey) ? \Resend::client($apiKey) : null;
    }
    
    public function send(string $to, string $templateId, array $data = [], array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Resend is not properly configured');
        }
        
        try {
            // Get HTML from template
            $html = $this->getTemplateHtml($templateId, $data);
            
            // Prepare email parameters
            $params = [
                'from' => sprintf(
                    '%s <%s>',
                    $options['from_name'] ?? $_ENV['DEFAULT_FROM_NAME'] ?? 'Skedi',
                    $options['from'] ?? $_ENV['DEFAULT_FROM_EMAIL'] ?? 'apis@skedi.com'
                ),
                'to' => [$to],
                'subject' => $data['subject'] ?? $this->getSubjectForTemplate($templateId, $data),
                'html' => $html
            ];
            
            // Add CC if provided
            if (!empty($options['cc'])) {
                $params['cc'] = is_array($options['cc']) ? $options['cc'] : [$options['cc']];
            }
            
            // Add BCC if provided
            if (!empty($options['bcc'])) {
                $params['bcc'] = is_array($options['bcc']) ? $options['bcc'] : [$options['bcc']];
            }
            
            // Add reply-to if provided
            if (!empty($options['reply_to'])) {
                $params['reply_to'] = $options['reply_to'];
            }
            
            // Add attachments if provided
            if (!empty($options['attachments'])) {
                $params['attachments'] = [];
                foreach ($options['attachments'] as $attachment) {
                    $params['attachments'][] = [
                        'filename' => $attachment['filename'],
                        'content' => base64_encode($attachment['content']),
                    ];
                }
            }
            
            // Log the sending attempt
            $this->logger->info('Sending Resend email', [
                'to' => $to,
                'template' => $templateId,
                'subject' => $params['subject']
            ]);
            
            // Send the email
            $response = $this->client->emails->send($params);
            
            return [
                'success' => !empty($response->id),
                'message_id' => $response->id ?? null,
                'status_code' => 200,
                'provider' => $this->getName()
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Resend email error', [
                'to' => $to,
                'template' => $templateId,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    public function sendBulk(array $recipients, string $templateId, array $globalData = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Resend is not properly configured');
        }
        
        try {
            $successCount = 0;
            $failedRecipients = [];
            
            // Process in batches of 100
            $batches = array_chunk($recipients, 100);
            
            foreach ($batches as $batch) {
                $batchEmails = [];
                
                foreach ($batch as $recipient) {
                    $recipientData = array_merge($globalData, $recipient['data'] ?? []);
                    
                    $emailParams = [
                        'from' => sprintf(
                            '%s <%s>',
                            $globalData['from_name'] ?? $_ENV['DEFAULT_FROM_NAME'] ?? 'Skedi',
                            $globalData['from'] ?? $_ENV['DEFAULT_FROM_EMAIL'] ?? 'apis@skedi.com'
                        ),
                        'to' => [$recipient['email']],
                        'subject' => $recipientData['subject'] ?? $this->getSubjectForTemplate($templateId, $recipientData),
                        'html' => $this->getTemplateHtml($templateId, $recipientData)
                    ];
                    
                    $batchEmails[] = $emailParams;
                }
                
                // Send batch
                try {
                    $response = $this->client->batch->send($batchEmails);
                    $successCount += count($batch);
                } catch (\Exception $e) {
                    $this->logger->error('Resend batch error', [
                        'error' => $e->getMessage(),
                        'batch_size' => count($batch)
                    ]);
                    foreach ($batch as $recipient) {
                        $failedRecipients[] = $recipient['email'];
                    }
                }
            }
            
            return [
                'success' => $successCount > 0,
                'provider' => $this->getName(),
                'recipient_count' => $successCount,
                'failed_recipients' => $failedRecipients
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Resend bulk email error', [
                'template' => $templateId,
                'recipient_count' => count($recipients),
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    public function getName(): string
    {
        return 'resend';
    }
    
    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && $this->client !== null;
    }
    
    /**
     * Get HTML content from template files
     */
    private function getTemplateHtml(string $templateId, array $data): string
    {
        // Check if template class exists
        $templateClass = $this->getTemplateClass($templateId);
        
        if (class_exists($templateClass)) {
            return $templateClass::render($data);
        }
        
        // Fallback to default template
        $this->logger->warning("Template class not found for: {$templateId}, using default");
        return $this->getDefaultTemplate($data);
    }
    
    /**
     * Map template ID to template class
     */
   private function getTemplateClass(string $templateId): string
    {
        $templates = [
            'meeting_scheduled' => MeetingScheduledTemplate::class,
            'meeting_scheduled_host' => MeetingScheduledHostTemplate::class,
            'meeting_reminder' => MeetingReminderTemplate::class,
            'meeting_cancelled' => MeetingCancelledTemplate::class,
            'booking_created' => BookingCreatedTemplate::class,
            'booking_confirmed' => BookingConfirmedTemplate::class,
            'invitation' => InvitationTemplate::class,
            'blank' => BlankTemplate::class,
            'password_reset' => PasswordResetTemplate::class,
            'password_reset_confirmation' => PasswordResetConfirmationTemplate::class,
            'email_verification' => EmailVerificationTemplate::class,
            'welcome' => WelcomeTemplate::class,
        ];
        
        return $templates[$templateId] ?? BlankTemplate::class;
    }
        
    /**
     * Get subject line for template - ONLY CHANGE IS HERE!
     */
    private function getSubjectForTemplate(string $templateId, array $data): string
    {
        $meetingStatus = $data['meeting_status'] ?? 'confirmed';
        
        
        // Check status for meeting templates only
        if ($templateId === 'meeting_scheduled' && $meetingStatus === 'pending') {
            return 'Booking Request Sent - Awaiting Approval';
        }
        
        if ($templateId === 'meeting_scheduled_host' && $meetingStatus === 'pending') {
            return 'Meeting Approval Required';
        }
        
        // Default subjects (your original working ones)
        $subjects = [
            'meeting_scheduled' => 'Your meeting has been scheduled',
            'meeting_scheduled_host' => 'New meeting booked',
            'meeting_reminder' => 'Meeting reminder',
            'booking_created' => 'New booking created',
            'booking_confirmed' => '✅ Your booking has been confirmed!',
            'invitation' => 'You\'ve been invited',
            'meeting_cancelled' => 'Meeting Cancelled',
            'blank' => $data['subject'] ?? 'Notification from Skedi',
        ];
        
        return $subjects[$templateId] ?? 'Notification from Skedi';
    }
    
    /**
     * Default template fallback
     */
    private function getDefaultTemplate(array $data): string
    {
        $content = $data['content'] ?? $data['message'] ?? 'No content provided';
        
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Notification</title>
</head>
<body>
    <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
        {$content}
    </div>
</body>
</html>
HTML;
    }
}