<?php

namespace App\Plugins\Email\Service;

interface EmailProviderInterface
{
    /**
     * Send an email using the provider
     * 
     * @param string $to Recipient email
     * @param string $templateId Template identifier
     * @param array $data Dynamic data for template
     * @param array $options Additional options (cc, bcc, attachments, etc.)
     * @return array Response from provider
     */
    public function send(string $to, string $templateId, array $data = [], array $options = []): array;
    
    /**
     * Send bulk emails
     * 
     * @param array $recipients Array of recipient data
     * @param string $templateId Template identifier
     * @param array $globalData Data common to all emails
     * @return array Response from provider
     */
    public function sendBulk(array $recipients, string $templateId, array $globalData = []): array;
    
    /**
     * Get provider name
     * 
     * @return string
     */
    public function getName(): string;
    
    /**
     * Check if provider is configured and ready
     * 
     * @return bool
     */
    public function isConfigured(): bool;
}