<?php
// src/Plugins/Workflows/Action/SendEmailAction.php

namespace App\Plugins\Workflows\Action;

use App\Plugins\Workflows\Interface\ActionInterface;
use App\Plugins\Email\Service\EmailService;

class SendEmailAction implements ActionInterface
{
    private EmailService $emailService;

    public function __construct(EmailService $emailService)
    {
        $this->emailService = $emailService;
    }

    public function getId(): string
    {
        return 'email.send';
    }

    public function getName(): string
    {
        return 'Send Email';
    }

    public function getDescription(): string
    {
        return 'Send an email to specified recipients';
    }

    public function getCategory(): string
    {
        return 'communication';
    }

    public function getIcon(): string
    {
        return 'PhEnvelope';
    }

    public function getConfigSchema(): array
    {
        return [
            'to' => [
                'type' => 'string',
                'label' => 'To Email',
                'placeholder' => '{{booking.customer_email}}',
                'required' => true,
                'description' => 'Recipient email address. You can use variables like {{booking.customer_email}}'
            ],
            'cc' => [
                'type' => 'string',
                'label' => 'CC Email',
                'placeholder' => 'cc@example.com',
                'required' => false
            ],
            'subject' => [
                'type' => 'string',
                'label' => 'Subject',
                'placeholder' => 'Booking Confirmation - {{event.name}}',
                'required' => true
            ],
            'body' => [
                'type' => 'textarea',
                'label' => 'Email Body',
                'placeholder' => 'Hi {{booking.customer_name}},\n\nYour booking for {{event.name}} is confirmed...',
                'required' => true,
                'rows' => 10
            ],
            'template_id' => [
                'type' => 'select',
                'label' => 'Email Template (optional)',
                'options' => [], // Will be populated from email templates
                'required' => false
            ]
        ];
    }

    public function execute(array $config, array $context): array
    {
        try {
            // Replace variables in config values
            $to = $this->replaceVariables($config['to'], $context);
            $cc = !empty($config['cc']) ? $this->replaceVariables($config['cc'], $context) : null;
            $subject = $this->replaceVariables($config['subject'], $context);
            $body = $this->replaceVariables($config['body'], $context);

            // Send email using the EmailService
            $this->emailService->send(
                $to,
                $subject,
                $body,
                $cc,
                null, // BCC
                !empty($config['template_id']) ? $config['template_id'] : null
            );

            return [
                'success' => true,
                'email_sent_to' => $to,
                'subject' => $subject
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['to'])) {
            $errors[] = 'Recipient email is required';
        }

        if (empty($config['subject'])) {
            $errors[] = 'Email subject is required';
        }

        if (empty($config['body']) && empty($config['template_id'])) {
            $errors[] = 'Email body or template is required';
        }

        return $errors;
    }

    private function replaceVariables(string $template, array $context): string
    {
        return preg_replace_callback('/\{\{(.+?)\}\}/', function($matches) use ($context) {
            $path = trim($matches[1]);
            $value = $this->getNestedValue($context, $path);
            return $value !== null ? $value : $matches[0];
        }, $template);
    }

    private function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $value = $data;

        foreach ($keys as $key) {
            if (is_array($value) && isset($value[$key])) {
                $value = $value[$key];
            } else {
                return null;
            }
        }

        return $value;
    }
}