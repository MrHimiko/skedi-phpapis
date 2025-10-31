<?php

namespace App\Plugins\Integrations\Common\Trait;

use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Common\Entity\IntegrationRateLimitEntity;
use App\Plugins\Integrations\Common\Exception\RateLimitException;
use App\Service\CrudManager;
use DateTime;

trait RateLimitingTrait
{
    protected CrudManager $crudManager;
    
    /**
     * Rate limit configurations per provider
     */
    protected array $rateLimits = [
        'google_calendar' => [
            'default' => ['requests' => 100, 'window' => 60], // 100 requests per minute
            'sync' => ['requests' => 10, 'window' => 60],     // 10 syncs per minute
            'create' => ['requests' => 50, 'window' => 60],   // 50 creates per minute
        ],
        'google_meet' => [
            'default' => ['requests' => 100, 'window' => 60],
            'create' => ['requests' => 30, 'window' => 60],
        ],
        'outlook_calendar' => [
            'default' => ['requests' => 60, 'window' => 60],
            'sync' => ['requests' => 5, 'window' => 60],
        ],
    ];
    
    /**
     * Check rate limit before making API call
     */
    protected function checkRateLimit(IntegrationEntity $integration, string $endpoint = 'default'): void
    {
        $provider = $integration->getProvider();
        $limits = $this->rateLimits[$provider][$endpoint] ?? $this->rateLimits[$provider]['default'] ?? null;
        
        if (!$limits) {
            return; // No rate limit configured
        }
        
        $windowStart = new DateTime("-{$limits['window']} seconds");
        
        try {
            $filters = [
                [
                    'field' => 'integrationId',
                    'operator' => 'equals',
                    'value' => $integration->getId()
                ],
                [
                    'field' => 'endpoint',
                    'operator' => 'equals',
                    'value' => $endpoint
                ],
                [
                    'field' => 'windowStart',
                    'operator' => 'greater_than_or_equal',
                    'value' => $windowStart
                ]
            ];
            
            $results = $this->crudManager->findMany(
                IntegrationRateLimitEntity::class,
                $filters,
                1,
                1000,
                []
            );
            
            $currentCount = array_sum(array_map(function($entity) {
                return $entity->getRequestsCount();
            }, $results));
            
            if ($currentCount >= $limits['requests']) {
                throw new RateLimitException(
                    "Rate limit exceeded for {$provider}:{$endpoint}. " .
                    "Limit: {$limits['requests']} requests per {$limits['window']} seconds."
                );
            }
            
            // Record this request
            $this->recordApiCall($integration, $endpoint);
        } catch (RateLimitException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Continue without rate limiting on error
        }
    }
    
    /**
     * Record an API call for rate limiting
     */
    protected function recordApiCall(IntegrationEntity $integration, string $endpoint): void
    {
        try {
            $now = new DateTime();
            
            // Try to find existing record for this minute
            $filters = [
                [
                    'field' => 'integrationId',
                    'operator' => 'equals',
                    'value' => $integration->getId()
                ],
                [
                    'field' => 'endpoint',
                    'operator' => 'equals',
                    'value' => $endpoint
                ],
                [
                    'field' => 'windowStart',
                    'operator' => 'equals',
                    'value' => $now
                ]
            ];
            
            $existing = $this->crudManager->findMany(
                IntegrationRateLimitEntity::class,
                $filters,
                1,
                1,
                []
            );
            
            if (!empty($existing)) {
                // Update existing
                $entity = $existing[0];
                $entity->incrementRequestsCount();
                $this->entityManager->persist($entity);
                $this->entityManager->flush();
            } else {
                // Create new
                $entity = new IntegrationRateLimitEntity();
                $this->crudManager->create($entity, [
                    'integrationId' => $integration->getId(),
                    'endpoint' => $endpoint,
                    'requestsCount' => 1,
                    'windowStart' => $now
                ]);
            }
        } catch (\Exception $e) {
            // Continue without recording
        }
    }
    
    /**
     * Clean up old rate limit records
     */
    protected function cleanupRateLimitRecords(): void
    {
        try {
            $cutoff = new DateTime('-2 hours');
            
            $filters = [
                [
                    'field' => 'windowStart',
                    'operator' => 'less_than',
                    'value' => $cutoff
                ]
            ];
            
            $oldRecords = $this->crudManager->findMany(
                IntegrationRateLimitEntity::class,
                $filters,
                1,
                10000,
                []
            );
            
            foreach ($oldRecords as $record) {
                $this->crudManager->delete($record, true);
            }
        } catch (\Exception $e) {
            // Continue
        }
    }
}