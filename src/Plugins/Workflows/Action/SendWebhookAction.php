<?php

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
                'type' => 'url',
                'label' => 'Webhook URL',
                'placeholder' => 'https://api.example.com/webhook',
                'required' => true,
                'description' => 'The URL to send the webhook to'
            ],
            'method' => [
                'type' => 'select',
                'label' => 'HTTP Method',
                'options' => [
                    ['label' => 'POST', 'value' => 'POST'],
                    ['label' => 'PUT', 'value' => 'PUT'],
                    ['label' => 'PATCH', 'value' => 'PATCH']
                ],
                'default' => 'POST',
                'required' => true
            ],
            'headers' => [
                'type' => 'textarea',
                'label' => 'Headers (JSON)',
                'placeholder' => '{"Authorization": "Bearer YOUR_TOKEN"}',
                'description' => 'Custom headers in JSON format',
                'required' => false,
                'rows' => 3
            ],
            'body' => [
                'type' => 'textarea',
                'label' => 'Body (JSON)',
                'placeholder' => '{"booking_id": "{{booking.id}}", "customer": "{{booking.customer_email}}"}',
                'description' => 'Request body in JSON format. You can use variables like {{booking.id}}',
                'required' => false,
                'rows' => 8
            ],
            'timeout' => [
                'type' => 'number',
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
            // Replace variables in URL
            $url = $this->replaceVariables($config['url'], $context);
            
            // Validate URL
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                throw new \Exception('Invalid webhook URL: ' . $url);
            }

            $method = strtoupper($config['method'] ?? 'POST');
            $timeout = (int)($config['timeout'] ?? 30);

            // Parse headers
            $headers = [];
            if (!empty($config['headers'])) {
                $headersString = $this->replaceVariables($config['headers'], $context);
                $parsedHeaders = json_decode($headersString, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Invalid JSON in headers: ' . json_last_error_msg());
                }
                
                $headers = $parsedHeaders;
            }

            // Parse body
            $body = null;
            if (!empty($config['body'])) {
                $bodyString = $this->replaceVariables($config['body'], $context);
                $parsedBody = json_decode($bodyString, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('Invalid JSON in body: ' . json_last_error_msg());
                }
                
                $body = $parsedBody;
            } else {
                // If no body specified, send entire context
                $body = $context;
            }

            // Add content-type header if not specified
            if (!isset($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/json';
            }

            // Send webhook
            $response = $this->httpClient->request($method, $url, [
                'headers' => $headers,
                'json' => $body,
                'timeout' => $timeout
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getContent(false);

            // Consider 2xx status codes as success
            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'url' => $url,
                    'method' => $method,
                    'status_code' => $statusCode,
                    'response' => $responseBody
                ];
            } else {
                throw new \Exception('Webhook returned status code ' . $statusCode . ': ' . $responseBody);
            }

        } catch (\Exception $e) {
            throw new \Exception('Failed to send webhook: ' . $e->getMessage());
        }
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (empty($config['url'])) {
            $errors[] = 'Webhook URL is required';
        } elseif (!filter_var($config['url'], FILTER_VALIDATE_URL)) {
            $errors[] = 'Invalid webhook URL format';
        }

        if (!empty($config['method'])) {
            $allowedMethods = ['POST', 'PUT', 'PATCH', 'GET'];
            if (!in_array(strtoupper($config['method']), $allowedMethods)) {
                $errors[] = 'Invalid HTTP method';
            }
        }

        // Validate headers JSON
        if (!empty($config['headers'])) {
            json_decode($config['headers']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Headers must be valid JSON';
            }
        }

        // Validate body JSON
        if (!empty($config['body'])) {
            json_decode($config['body']);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = 'Body must be valid JSON';
            }
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