<?php

namespace App\Plugins\Integrations\Common\Abstract;

use App\Plugins\Integrations\Common\Interface\IntegrationInterface;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use App\Plugins\Integrations\Common\Trait\CachingTrait;
use App\Plugins\Integrations\Common\Trait\RateLimitingTrait;
use App\Plugins\Account\Entity\UserEntity;
use Doctrine\ORM\EntityManagerInterface;
use DateTime;

abstract class BaseIntegration implements IntegrationInterface
{
    use CachingTrait;
    use RateLimitingTrait;
    
    protected EntityManagerInterface $entityManager;
    protected IntegrationRepository $integrationRepository;
    
    public function __construct(
        EntityManagerInterface $entityManager,
        IntegrationRepository $integrationRepository
    ) {
        $this->entityManager = $entityManager;
        $this->integrationRepository = $integrationRepository;
    }
    
    /**
     * Get OAuth configuration
     */
    abstract protected function getOAuthConfig(): array;
    
    /**
     * Exchange code for tokens
     */
    abstract protected function exchangeCodeForToken(string $code): array;
    
    /**
     * Get user information from provider
     */
    abstract protected function getUserInfo(array $tokenData): array;
    
    /**
     * {@inheritdoc}
     */
    public function getAuthUrl(): string
    {
        $config = $this->getOAuthConfig();
        
        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'scope' => $config['scope'],
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
        
        return $config['auth_url'] . '?' . http_build_query($params);
    }
    
    /**
     * {@inheritdoc}
     */
    public function handleAuthCallback(UserEntity $user, string $code): IntegrationEntity
    {
        try {
            // Exchange code for tokens
            $tokenData = $this->exchangeCodeForToken($code);
            
            if (isset($tokenData['error'])) {
                throw new IntegrationException('Failed to get access token: ' . 
                    ($tokenData['error_description'] ?? $tokenData['error']));
            }
            
            // Get user info
            $userInfo = $this->getUserInfo($tokenData);
            
            // Create or update integration
            $integration = $this->createOrUpdateIntegration($user, $tokenData, $userInfo);
            
            return $integration;
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to authenticate: ' . $e->getMessage());
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function getUserIntegration(UserEntity $user, ?int $integrationId = null): ?IntegrationEntity
    {
        if ($integrationId) {
            $integration = $this->integrationRepository->find($integrationId);
            if ($integration && 
                $integration->getUser()->getId() === $user->getId() && 
                $integration->getProvider() === $this->getProvider() && 
                $integration->getStatus() === 'active') {
                return $integration;
            }
            return null;
        }
        
        return $this->integrationRepository->findOneBy([
            'user' => $user,
            'provider' => $this->getProvider(),
            'status' => 'active'
        ], ['created' => 'DESC']);
    }
    
    /**
     * Create or update integration
     */
    protected function createOrUpdateIntegration(UserEntity $user, array $tokenData, array $userInfo): IntegrationEntity
    {
        // Check for existing integration
        $existing = $this->integrationRepository->findOneBy([
            'user' => $user,
            'provider' => $this->getProvider(),
            'status' => 'active'
        ]);
        
        $integration = $existing ?: new IntegrationEntity();
        
        // Calculate token expiration
        $expiresIn = $tokenData['expires_in'] ?? 3600;
        $expiresAt = new DateTime();
        $expiresAt->modify("+{$expiresIn} seconds");
        
        // Build integration name
        $integrationName = $this->getProvider();
        if (!empty($userInfo['email'])) {
            $integrationName .= ' (' . $userInfo['email'] . ')';
        }
        
        if (!$existing) {
            $integration->setUser($user);
            $integration->setProvider($this->getProvider());
            $integration->setExternalId($userInfo['id'] ?? uniqid($this->getProvider() . '_'));
        }
        
        $integration->setName($integrationName);
        $integration->setAccessToken($tokenData['access_token']);
        
        if (isset($tokenData['refresh_token'])) {
            $integration->setRefreshToken($tokenData['refresh_token']);
        }
        
        $integration->setTokenExpires($expiresAt);
        
        if (isset($tokenData['scope'])) {
            $integration->setScopes($tokenData['scope']);
        }
        
        // Store user info in config
        $config = $integration->getConfig() ?? [];
        if (!empty($userInfo['email'])) {
            $config['email'] = $userInfo['email'];
        }
        $integration->setConfig($config);
        
        $integration->setStatus('active');
        
        $this->entityManager->persist($integration);
        $this->entityManager->flush();
        
        return $integration;
    }
    
    /**
     * Refresh token if needed
     */
    protected function refreshTokenIfNeeded(IntegrationEntity $integration): void
    {
        if (!$integration->getTokenExpires() || $integration->getTokenExpires() > new DateTime('+5 minutes')) {
            return;
        }
        
        if (!$integration->getRefreshToken()) {
            throw new IntegrationException('No refresh token available');
        }
        
        try {
            $tokenData = $this->refreshToken($integration);
            
            // Update tokens
            $expiresIn = $tokenData['expires_in'] ?? 3600;
            $expiresAt = new DateTime();
            $expiresAt->modify("+{$expiresIn} seconds");
            
            $integration->setAccessToken($tokenData['access_token']);
            $integration->setTokenExpires($expiresAt);
            
            if (isset($tokenData['refresh_token'])) {
                $integration->setRefreshToken($tokenData['refresh_token']);
            }
            
            $this->entityManager->persist($integration);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            throw new IntegrationException('Failed to refresh token: ' . $e->getMessage());
        }
    }
    
    /**
     * Refresh access token
     */
    abstract protected function refreshToken(IntegrationEntity $integration): array;
}