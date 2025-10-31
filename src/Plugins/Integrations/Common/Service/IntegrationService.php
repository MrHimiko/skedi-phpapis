<?php

namespace App\Plugins\Integrations\Common\Service;


use Doctrine\ORM\EntityManagerInterface;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Integrations\Common\Entity\IntegrationEntity;
use App\Plugins\Integrations\Common\Repository\IntegrationRepository;
use App\Plugins\Integrations\Common\Exception\IntegrationException;
use App\Plugins\Account\Service\UserAvailabilityService;
use App\Service\CrudManager;
use DateTime;

/**
 * Base service for handling integrations with external services.
 * Provides common functionality for all integration types.
 */
class IntegrationService
{
    protected EntityManagerInterface $entityManager;
    protected IntegrationRepository $integrationRepository;
    protected UserAvailabilityService $userAvailabilityService;
    protected CrudManager $crudManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        IntegrationRepository $integrationRepository,
        UserAvailabilityService $userAvailabilityService,
        CrudManager $crudManager
    ) {
        $this->entityManager = $entityManager;
        $this->integrationRepository = $integrationRepository;
        $this->userAvailabilityService = $userAvailabilityService;
        $this->crudManager = $crudManager;
    }

    /**
     * Get all user integrations
     */
    public function getUserIntegrations(UserEntity $user, ?string $provider = null): array
    {
        return $this->integrationRepository->findByUser($user, $provider);
    }

    /**
     * Get a specific integration
     */
    public function getIntegration(int $id, UserEntity $user): ?IntegrationEntity
    {
        $integration = $this->integrationRepository->find($id);
        
        if (!$integration || $integration->getUser()->getId() !== $user->getId()) {
            return null;
        }
        
        return $integration;
    }

    /**
     * Create a new integration
     */
    public function createIntegration(
        UserEntity $user,
        string $provider,
        string $name,
        ?string $externalId = null,
        ?string $accessToken = null,
        ?string $refreshToken = null,
        ?DateTime $tokenExpires = null,
        ?string $scopes = null,
        ?array $config = null
    ): IntegrationEntity {
        $integration = new IntegrationEntity();
        
        $integration->setUser($user);
        $integration->setProvider($provider);
        $integration->setName($name);
        
        if ($externalId) {
            $integration->setExternalId($externalId);
        }
        
        if ($accessToken) {
            $integration->setAccessToken($accessToken);
        }
        
        if ($refreshToken) {
            $integration->setRefreshToken($refreshToken);
        }
        
        if ($tokenExpires) {
            $integration->setTokenExpires($tokenExpires);
        }
        
        if ($scopes) {
            $integration->setScopes($scopes);
        }
        
        if ($config) {
            $integration->setConfig($config);
        }
        
        $this->entityManager->persist($integration);
        $this->entityManager->flush();
        
        return $integration;
    }

    /**
     * Update integration
     */
    public function updateIntegration(IntegrationEntity $integration, array $data): IntegrationEntity
    {
        if (isset($data['name'])) {
            $integration->setName($data['name']);
        }
        
        if (isset($data['access_token'])) {
            $integration->setAccessToken($data['access_token']);
        }
        
        if (isset($data['refresh_token'])) {
            $integration->setRefreshToken($data['refresh_token']);
        }
        
        if (isset($data['token_expires'])) {
            $integration->setTokenExpires($data['token_expires']);
        }
        
        if (isset($data['scopes'])) {
            $integration->setScopes($data['scopes']);
        }
        
        if (isset($data['config'])) {
            $integration->setConfig($data['config']);
        }
        
        if (isset($data['status'])) {
            $integration->setStatus($data['status']);
        }
        
        if (isset($data['last_synced'])) {
            $integration->setLastSynced($data['last_synced']);
        }
        
        $this->entityManager->persist($integration);
        $this->entityManager->flush();
        
        return $integration;
    }

    /**
     * Delete integration
     */
    public function deleteIntegration(IntegrationEntity $integration): void
    {
        $this->entityManager->remove($integration);
        $this->entityManager->flush();
    }

    /**
     * Get available integration providers
     */
    public function getAvailableProviders(): array
    {
        return [
            'google_calendar' => [
                'name' => 'Google Calendar',
                'description' => 'Sync your Google Calendar events',
                'icon' => 'calendar',
                'scopes' => ['https://www.googleapis.com/auth/calendar.readonly', 'https://www.googleapis.com/auth/calendar.events']
            ],
            // Add more providers here as they are implemented
        ];
    }
}