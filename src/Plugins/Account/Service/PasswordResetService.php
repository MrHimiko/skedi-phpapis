<?php

namespace App\Plugins\Account\Service;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use App\Service\ValidatorService;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Account\Entity\PasswordResetTokenEntity;
use App\Plugins\Account\Exception\AccountException;
use App\Plugins\Email\Service\EmailService;

class PasswordResetService
{
    private EntityManagerInterface $entityManager;
    private ValidatorService $validatorService;
    private EmailService $emailService;

    public function __construct(
        EntityManagerInterface $entityManager,
        ValidatorService $validatorService,
        EmailService $emailService
    ) {
        $this->entityManager = $entityManager;
        $this->validatorService = $validatorService;
        $this->emailService = $emailService;
    }

    public function requestPasswordReset(array $data): void
    {
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

        $user = $this->entityManager->getRepository(UserEntity::class)->findOneBy([
            'email' => $data['email']
        ]);

        if (!$user) {
            return;
        }

        if ($user->getPassword() === null) {
            return;
        }

        $existingTokens = $this->entityManager->getRepository(PasswordResetTokenEntity::class)->findBy([
            'user' => $user,
            'used' => false
        ]);

        foreach ($existingTokens as $token) {
            $token->setUsed(true);
        }

        $tokenString = bin2hex(random_bytes(32));
        $token = new PasswordResetTokenEntity();
        $token->setUser($user);
        $token->setToken($tokenString);
        $token->setExpiresAt((new \DateTime())->modify('+1 hour'));

        $this->entityManager->persist($token);
        $this->entityManager->flush();

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

        $tokenEntity = $this->entityManager->getRepository(PasswordResetTokenEntity::class)->findOneBy([
            'token' => $data['token'],
            'used' => false
        ]);

        if (!$tokenEntity) {
            throw new AccountException('Invalid or expired reset token.');
        }

        if ($tokenEntity->isExpired()) {
            $tokenEntity->setUsed(true);
            $this->entityManager->flush();
            throw new AccountException('Reset token has expired.');
        }

        $user = $tokenEntity->getUser();
        
        if ($user->getPassword() === null) {
            throw new AccountException('This account uses Google sign-in. Please sign in with Google instead.');
        }

        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $user->setPassword($hashedPassword);
        $user->setUpdated(new \DateTime());

        $this->entityManager->persist($user);
        
        $tokenEntity->setUsed(true);
        $this->entityManager->persist($tokenEntity);
        
        $this->entityManager->flush();

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

        $user = $tokenEntity->getUser();
        if ($user->getPassword() === null) {
            return false;
        }

        return true;
    }
}