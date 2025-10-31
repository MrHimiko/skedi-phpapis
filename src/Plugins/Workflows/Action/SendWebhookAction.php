<?php
// src/Plugins/Workflows/Action/SendWebhookAction.php

namespace App\Plugins\Workflows\Action;

use App\Plugins\Workflows\Interface\ActionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SendWebhookAction implements ActionInterface
{
    private HttpClientInterface $httpClient;

    public function __construct(HttpClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function getId(): string
    {
        return 'webhook.send';
    }

    public function getName(): string
    {
        return 'Send Webhook';
    }

    public function getDescription(): string
    {
        return 'Send data to an external URL via HTTP request';
    }

    public function getCategory(): string
    {
        return 'integration';
    }

    public function getIcon(): string
    {
        return 'PhWebhooksLogo';
    }

    public function getConfigSchema(): array
    {
        return [
            'url' => [
                'type' => 'string',
                'label' => 'Webhook URL',
                'placeholder' => 'https://example.com/webhook',
                'required' => true,
                'description' => 'The URL to send the webhook to'
            ],
            'method' => [
                'type' => 'select',
                'label' => 'HTTP Method',
                'options' => ['POST', 'PUT', 'PATCH', 'GET'],
                'default' => 'POST',
                'required' => true
            ],
            'headers' => [
                'type' => 'json',
                'label' => 'Headers (JSON)',
                'placeholder' => '{"Authorization": "Bearer {{api_token}}"}',
                'description' => 'Custom headers in JSON format. You can use variables.',
                'required' => false
            ],
            'body' => [
                'type' => 'json',
                'label' => 'Body (JSON)',
                'placeholder' => '{"booking_id": "{{booking.id}}", "status": "{{booking.status}}"}',
                'description' => 'Request body in JSON format. You can use variables.',
                'required' => false
            ],
            'timeout' => [
                'type' => 'integer',
                'label' => 'Timeout (seconds)',
                'default' => 30,
                'min' => 1,
                'max' => 300,
                'required' => false
            ]
        ];
    }

    public function execute(array $config, array $context): array
    {
        try {
            // Replace variables in all config values
            $url = $this->replaceVariables($config['url'], $context);
            $method = $config['method'] ?? 'POST';
            $timeout = $config['timeout'] ?? 30;
            
            // Prepare headers
            $headers = ['Content-Type' => 'application/json'];
            if (!empty($config['headers'])) {
                $customHeaders = json_decode($this->replaceVariables($config['headers'], $context), true);
                if (is_array($customHeaders)) {
                    $headers = array_merge($headers, $customHeaders);
                }
            }
            
            // Prepare body
            $body = null;
            if (!empty($config['body']) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $body = $this->replaceVariables($config['body'], $context);
            }
            
            // Send the webhook
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $timeout
            ]);
            
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);
            
            return [
                'success' => $statusCode >= 200 && $statusCode < 300,
                'status_code' => $statusCode,
                'response' => json_decode($responseBody, true) ?? $responseBody,
                'url' => $url,
                'method' => $method
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'url' => $url ?? $config['url'],
                'method' => $method ?? 'POST'
            ];
        }
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['url'])) {
            $errors[] = 'Webhook URL is required';
        } elseif (!filter_var($config['url'], FILTER_VALIDATE_URL) && !preg_match('/\{\{.+?\}\}/', $config['url'])) {
            $errors[] = 'Invalid webhook URL';
        }

        if (!empty($config['headers'])) {
            $headers = json_decode($config['headers'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Headers must be valid JSON';
            }
        }

        if (!empty($config['body'])) {
            $testBody = preg_replace('/\{\{.+?\}\}/', '"test"', $config['body']);
            json_decode($testBody);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Body must be valid JSON (variables allowed)';
            }
        }

        return $errors;
    }

    private function replaceVariables(string $template, array $context): string
    {
        return preg_replace_callback('/\{\{(.+?)\}\}/', function($matches) use ($context) {
            $path = trim($matches[1]);
            $value = $this->getNestedValue($context, $path);
            
            if ($value === null) {
                return $matches[0];
            }
            
            // Convert non-string values to JSON
            if (!is_string($value)) {
                return json_encode($value);
            }
            
            return $value;
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