<?php

namespace App\Plugins\Integrations\Common\Interface;

use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;

interface IntegrationInterface
{
    /**
     * Get OAuth authorization URL
     */
    public function getAuthUrl(): string;
    
    /**
     * Handle OAuth callback
     */
    public function handleAuthCallback(UserEntity $user, string $code): IntegrationEntity;
    
    /**
     * Get user integration
     */
    public function getUserIntegration(UserEntity $user, ?int $integrationId = null): ?IntegrationEntity;
    
    /**
     * Get provider name
     */
    public function getProvider(): string;
}