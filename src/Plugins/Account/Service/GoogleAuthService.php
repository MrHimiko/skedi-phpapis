<?php

namespace App\Plugins\Account\Service;

use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Organizations\Entity\OrganizationEntity;  
use App\Plugins\Account\Service\LoginService;
use App\Plugins\Account\Service\RegisterService;
use App\Plugins\Account\Repository\UserRepository;
use App\Plugins\Account\Exception\AccountException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpClient\HttpClient;

class GoogleAuthService
{
    private LoginService $loginService;
    private RegisterService $registerService;
    private UserRepository $userRepository;
    private EntityManagerInterface $entityManager;

    public function __construct(
        LoginService $loginService,
        RegisterService $registerService,
        UserRepository $userRepository,
        EntityManagerInterface $entityManager
    ) {
        $this->loginService = $loginService;
        $this->registerService = $registerService;
        $this->userRepository = $userRepository;
        $this->entityManager = $entityManager;
    }

    public function getAuthUrl(): string
    {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? null;
        
        if (!$clientId) {
            throw new AccountException('GOOGLE_CLIENT_ID not found in environment. Check your .env file.');
        }
        
        $redirectUri = 'https://app.skedi.com/oauth/google/callback';
        
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => bin2hex(random_bytes(16))
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function handleCallback(string $code): array
    {
        try {
            // Exchange code for tokens
            $tokens = $this->exchangeCodeForTokens($code);
            
            // Get user info from Google
            $googleUser = $this->getGoogleUserInfo($tokens['access_token']);
            
            // Check if user exists
            $existingUser = $this->userRepository->findOneBy([
                'email' => $googleUser['email'],
                'deleted' => false
            ]);

            if ($existingUser) {
                // Login existing user
                $token = $this->loginService->createToken($existingUser);
                return [
                    'type' => 'login',
                    'user' => $existingUser->toArray(),
                    'token' => $token->getValue(),
                    'expires' => $token->getExpires()->format('Y-m-d H:i:s')
                ];
            } else {
                // Register new user - DON'T create organization here, let RegisterService handle it
                $userData = [
                    'name' => $googleUser['name'],
                    'email' => $googleUser['email']
                ];

                // Use null for organization - let the register method handle organization creation
                $user = $this->registerUserFromGoogle($userData);
                
                $token = $this->loginService->createToken($user);
                return [
                    'type' => 'register',
                    'user' => $user->toArray(),
                    'token' => $token->getValue(),
                    'expires' => $token->getExpires()->format('Y-m-d H:i:s')
                ];
            }
        } catch (\Exception $e) {
            throw new AccountException('Google authentication failed: ' . $e->getMessage());
        }
    }

    private function exchangeCodeForTokens(string $code): array
    {
        $clientId = $_ENV['GOOGLE_CLIENT_ID'];
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'];
        $redirectUri = 'https://app.skedi.com/oauth/google/callback';

        $httpClient = HttpClient::create();
        $response = $httpClient->request('POST', 'https://oauth2.googleapis.com/token', [
            'json' => [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code'
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Failed to exchange code for tokens');
        }

        return $response->toArray();
    }

    private function getGoogleUserInfo(string $accessToken): array
    {
        $httpClient = HttpClient::create();
        $response = $httpClient->request('GET', 'https://www.googleapis.com/oauth2/v2/userinfo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \Exception('Failed to fetch user info from Google');
        }

        return $response->toArray();
    }

    private function registerUserFromGoogle(array $userData): UserEntity
    {
        $this->entityManager->beginTransaction();
        
        try {
            // Create user entity directly
            $user = new UserEntity();
            $user->setName($userData['name']);
            $user->setEmail($userData['email']);
            $user->setPassword(null); // No password for Google users
            
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            
            // Create organization
            $firstName = explode(' ', $userData['name'])[0];
            $orgName = $firstName . "'s Organization";
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]/', '-', $firstName . "-organization")) . '-' . time();
            
            $organization = new OrganizationEntity();
            $organization->setName($orgName);
            $organization->setSlug($slug);
            
            $this->entityManager->persist($organization);
            $this->entityManager->flush();
            
            // Create user-organization relationship
            $userOrg = new \App\Plugins\Organizations\Entity\UserOrganizationEntity();
            $userOrg->setUser($user);
            $userOrg->setOrganization($organization);
            $userOrg->setRole('admin');
            
            $this->entityManager->persist($userOrg);
            $this->entityManager->flush();
            
            $this->entityManager->commit();
            
            return $user;
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw new AccountException('Failed to register user: ' . $e->getMessage());
        }
    }
}