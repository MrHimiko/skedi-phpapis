<?php

namespace App\Plugins\Integrations\Common\Trait;

use App\Plugins\Integrations\Common\Entity\IntegrationCacheEntity;
use App\Service\CrudManager;
use DateTime;

trait CachingTrait
{
    protected CrudManager $crudManager;
    
    /**
     * Default cache TTL in seconds
     */
    protected int $defaultCacheTTL = 3600; // 1 hour
    
    /**
     * Cache TTL configurations per data type
     */
    protected array $cacheTTLs = [
        'calendars_list' => 3600,      // 1 hour
        'user_info' => 86400,          // 24 hours
        'event_details' => 300,        // 5 minutes
        'meeting_link' => 2592000,     // 30 days
    ];
    
    /**
     * Get data from cache
     */
    protected function getFromCache(string $key): mixed
    {
        try {
            $filters = [
                [
                    'field' => 'cacheKey',
                    'operator' => 'equals',
                    'value' => $key
                ],
                [
                    'field' => 'expiresAt',
                    'operator' => 'greater_than',
                    'value' => new DateTime()
                ]
            ];
            
            $results = $this->crudManager->findMany(
                IntegrationCacheEntity::class,
                $filters,
                1,
                1,
                []
            );
            
            if (!empty($results)) {
                return $results[0]->getCacheValue();
            }
            
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Store data in cache
     */
    protected function setCache(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl === null) {
            $ttl = $this->defaultCacheTTL;
        }
        
        try {
            $expiresAt = new DateTime("+{$ttl} seconds");
            
            // Check if exists
            $existing = $this->crudManager->findOne(IntegrationCacheEntity::class, 0, ['cacheKey' => $key]);
            
            if ($existing) {
                // Update existing
                $this->crudManager->update($existing, [
                    'cacheValue' => $value,
                    'expiresAt' => $expiresAt
                ]);
            } else {
                // Create new
                $entity = new IntegrationCacheEntity();
                $this->crudManager->create($entity, [
                    'cacheKey' => $key,
                    'cacheValue' => $value,
                    'expiresAt' => $expiresAt
                ]);
            }
        } catch (\Exception $e) {
            // Continue without caching
        }
    }
    
    /**
     * Delete from cache
     */
    protected function deleteFromCache(string $key): void
    {
        try {
            $filters = [
                [
                    'field' => 'cacheKey',
                    'operator' => 'equals',
                    'value' => $key
                ]
            ];
            
            $results = $this->crudManager->findMany(
                IntegrationCacheEntity::class,
                $filters,
                1,
                1,
                []
            );
            
            if (!empty($results)) {
                $this->crudManager->delete($results[0], true);
            }
        } catch (\Exception $e) {
            // Continue
        }
    }
    
    /**
     * Clear cache by pattern
     */
    protected function clearCacheByPattern(string $pattern): void
    {
        try {
            $filters = [
                [
                    'field' => 'cacheKey',
                    'operator' => 'like',
                    'value' => $pattern
                ]
            ];
            
            $results = $this->crudManager->findMany(
                IntegrationCacheEntity::class,
                $filters,
                1,
                10000,
                []
            );
            
            foreach ($results as $entity) {
                $this->crudManager->delete($entity, true);
            }
        } catch (\Exception $e) {
            // Continue
        }
    }
    
    /**
     * Generate cache key
     */
    protected function generateCacheKey(string $prefix, ...$parts): string
    {
        $key = $prefix;
        foreach ($parts as $part) {
            if (is_array($part)) {
                $part = md5(serialize($part));
            }
            $key .= ':' . $part;
        }
        return $key;
    }
    
    /**
     * Get or set cache (convenience method)
     */
    protected function remember(string $key, callable $callback, ?int $ttl = null): mixed
    {
        $cached = $this->getFromCache($key);
        
        if ($cached !== null) {
            return $cached;
        }
        
        $value = $callback();
        
        if ($value !== null) {
            $this->setCache($key, $value, $ttl);
        }
        
        return $value;
    }
}