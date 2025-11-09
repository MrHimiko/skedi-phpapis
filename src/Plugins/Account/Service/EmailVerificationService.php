<?php

namespace App\Plugins\Account\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Service\CrudManager;
use App\Plugins\Account\Entity\UserEntity;
use App\Plugins\Account\Entity\EmailVerificationTokenEntity;
use App\Plugins\Account\Exception\AccountException;
use App\Plugins\Email\Service\EmailService;

class EmailVerificationService
{
    private EntityManagerInterface $entityManager;
    private EmailService $emailService;
    private CrudManager $crudManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        EmailService $emailService,
        CrudManager $crudManager
    ) {
        $this->entityManager = $entityManager;
        $this->emailService = $emailService;
        $this->crudManager = $crudManager;
    }

    public function sendVerificationEmail(UserEntity $user): void
    {
        if ($user->isEmailVerified()) {
            return;
        }

        $existingTokens = $this->entityManager->getRepository(EmailVerificationTokenEntity::class)->findBy([
            'user' => $user,
            'used' => false
        ]);

        foreach ($existingTokens as $token) {
            $token->setUsed(true);
        }

        $token = new EmailVerificationTokenEntity();
        $token->setUser($user);
        $token->setToken(bin2hex(random_bytes(32)));
        $token->setExpiresAt((new \DateTime())->modify('+24 hours'));

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $verifyUrl = $_ENV['APP_URL'] . '/account/verify-email?token=' . $token->getToken();
        
        $this->emailService->send(
            $user->getEmail(),
            'email_verification',
            [
                'subject' => 'Verify Your Email Address',
                'user_name' => $user->getName(),
                'verification_url' => $verifyUrl,
                'expires_in' => '24 hours'
            ]
        );
    }

    public function verifyEmail(string $token): UserEntity
    {
        $tokenEntity = $this->entityManager
            ->getRepository(EmailVerificationTokenEntity::class)
            ->findOneBy(['token' => $token, 'used' => false]);

        if (!$tokenEntity) {
            throw new AccountException('Invalid verification token.');
        }

        if ($tokenEntity->isExpired()) {
            $tokenEntity->setUsed(true);
            $this->entityManager->flush();
            throw new AccountException('Verification token has expired.');
        }

        $user = $tokenEntity->getUser();
        
        if (!$user->isEmailVerified()) {
            $this->crudManager->update($user, [
                'emailVerified' => true,
                'emailVerifiedAt' => new \DateTime()
            ]);
        }

        $tokenEntity->setUsed(true);
        $this->entityManager->flush();

        $this->emailService->send(
            $user->getEmail(),
            'welcome',
            [
                'subject' => 'Welcome!',
                'user_name' => $user->getName()
            ]
        );

        return $user;
    }

    public function resendVerificationEmail(UserEntity $user): void
    {
        if ($user->isEmailVerified()) {
            throw new AccountException('Email is already verified.');
        }

        $recentToken = $this->entityManager->getRepository(EmailVerificationTokenEntity::class)->findOneBy([
            'user' => $user,
            'used' => false
        ], ['createdAt' => 'DESC']);

        if ($recentToken) {
            $timeSinceLastToken = (new \DateTime())->getTimestamp() - $recentToken->getCreatedAt()->getTimestamp();
            if ($timeSinceLastToken < 300) {
                throw new AccountException('Please wait before requesting another verification email.');
            }
        }

        $this->sendVerificationEmail($user);
    }
}