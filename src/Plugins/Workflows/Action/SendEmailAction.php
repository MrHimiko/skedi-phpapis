<?php

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
            ]
        ];
    }

    public function execute(array $config, array $context): array
    {
        try {
            // Replace variables in config values
            $to = $this->replaceVariables($config['to'], $context);
            $subject = $this->replaceVariables($config['subject'], $context);
            $body = $this->replaceVariables($config['body'], $context);

            // Validate email
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new \Exception('Invalid email address: ' . $to);
            }

            // Send email using EmailService
            // Use 'workflow_email' template with subject and body in data
            $result = $this->emailService->send(
                $to,
                'workflow_email',
                [
                    'subject' => $subject,
                    'content' => nl2br(htmlspecialchars($body))
                ]
            );

            return [
                'success' => true,
                'email_sent_to' => $to,
                'subject' => $subject,
                'queued' => true
            ];
        } catch (\Exception $e) {
            throw new \Exception('Failed to send email: ' . $e->getMessage());
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

        if (empty($config['body'])) {
            $errors[] = 'Email body is required';
        }

        return $errors;
    }

    /**
     * Replace variables in template with context values
     */
    private function replaceVariables(string $template, array $context): string
    {
        return preg_replace_callback('/\{\{(.+?)\}\}/', function($matches) use ($context) {
            $path = trim($matches[1]);
            $value = $this->getNestedValue($context, $path);
            return $value !== null ? (string)$value : $matches[0];
        }, $template);
    }

    /**
     * Get nested value from array using dot notation
     */
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