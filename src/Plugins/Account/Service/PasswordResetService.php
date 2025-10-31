<?php

namespace App\Plugins\Account\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints as Assert;
use App\Service\ValidatorService;
use App\Service\CrudManager;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Account\Entity\PasswordResetTokenEntity;
use App\Plugins\Account\Exception\AccountException;
use App\Plugins\Email\Service\EmailService;

class PasswordResetService
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private ValidatorService $validatorService;
    private EmailService $emailService;
    private CrudManager $crudManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorService $validatorService,
        EmailService $emailService,
        CrudManager $crudManager
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->validatorService = $validatorService;
        $this->emailService = $emailService;
        $this->crudManager = $crudManager;
    }

    
    public function requestPasswordReset(array $data): void
    {
        // Validate email
        $constraints = new Assert\Collection([
            'email' => [
                new Assert\NotBlank(['message' => 'Email is required.']),
                new Assert\Email(['message' => 'Invalid email format.']),
            ],
        ]);

        if($errors = $this->validatorService->toArray($constraints, $data))
        {
            throw new AccountException(implode(' | ', $errors));
        }

        // Find user by email
        $user = $this->entityManager->getRepository(UserEntity::class)->findOneBy([
            'email' => $data['email']
        ]);

        // Always return success to prevent email enumeration
        if (!$user) {
            return;
        }

        // Invalidate any existing tokens for this user
        $existingTokens = $this->entityManager->getRepository(PasswordResetTokenEntity::class)->findBy([
            'user' => $user,
            'used' => false
        ]);

        foreach ($existingTokens as $token) {
            $token->setUsed(true);
        }

        // Create new token
        $token = new PasswordResetTokenEntity();
        $token->setUser($user);
        $token->setToken(bin2hex(random_bytes(32)));
        $token->setExpiresAt((new \DateTime())->modify('+1 hour'));

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        // Send email
        $resetUrl = $_ENV['APP_URL'] . '/account/reset-password?token=' . $token->getToken();
        
        $this->emailService->send(
            $user->getEmail(),
            'password_reset',
            [
                'subject' => 'Reset Your Password',
                'user_name' => $user->getName(),
                'reset_url' => $resetUrl,
                'expires_in' => '1 hour'
            ]
        );
    }

    public function resetPassword(array $data): void
    {
        // Validate input
        $constraints = new Assert\Collection([
            'token' => [
                new Assert\NotBlank(['message' => 'Token is required.']),
            ],
            'password' => [
                new Assert\NotBlank(['message' => 'Password is required.']),
                new Assert\Length(['min' => 8, 'minMessage' => 'Password must be at least 8 characters.']),
            ],
        ]);

        if($errors = $this->validatorService->toArray($constraints, $data))
        {
            throw new AccountException(implode(' | ', $errors));
        }

        // Find token
        $tokenEntity = $this->entityManager->getRepository(PasswordResetTokenEntity::class)->findOneBy([
            'token' => $data['token'],
            'used' => false
        ]);

        if (!$tokenEntity) {
            throw new AccountException('Invalid or expired reset token.');
        }

        // Check if token is expired
        if ($tokenEntity->isExpired()) {
            $tokenEntity->setUsed(true);
            $this->entityManager->flush();
            throw new AccountException('Reset token has expired.');
        }

        // Update password
        $user = $tokenEntity->getUser();
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $this->crudManager->update($user, [
            'password' => $hashedPassword
        ]);

        // Mark token as used
        $tokenEntity->setUsed(true);
        $this->entityManager->flush();

        // Send confirmation email
        $this->emailService->send(
            $user->getEmail(),
            'password_reset_confirmation',
            [
                'subject' => 'Password Successfully Reset',
                'user_name' => $user->getName()
            ]
        );
    }

    public function validateToken(string $token): bool
    {
        $tokenEntity = $this->entityManager->getRepository(PasswordResetTokenEntity::class)->findOneBy([
            'token' => $token,
            'used' => false
        ]);

        if (!$tokenEntity || $tokenEntity->isExpired()) {
            return false;
        }

        return true;
    }
}