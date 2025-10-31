<?php

namespace App\Plugins\Account\Service;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;

use Symfony\Component\Validator\Constraints as Assert;

use App\Service\ValidatorService;

use App\Plugins\Account\Entity\TokenEntity;
use App\Plugins\Account\Entity\UserEntity;

use App\Plugins\Account\Exception\AccountException;

class LoginService
{
    private EntityManagerInterface $entityManager;
    private ValidatorService $validatorService; 

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ValidatorService $validatorService
    ) {
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->validatorService = $validatorService;
    }

    public function login(array $data): TokenEntity
    {
        try 
        {
            $this->validate($data);

            if(!$user = $this->getUser($data['email']))
            {
                throw new AccountException('Invalid email or password.');
            }

            if(!password_verify($data['password'], $user->getPassword()))
            {
                throw new AccountException('Invalid email or password.');
            }

            return $this->createToken($user);
        }
        catch(AccountException $e)
        {
            throw $e;
        }
        catch(\Exception $e)
        {
            throw $e;
        }
    }

    /**
     * Get user by email
     */
    private function getUser(string $email): ?UserEntity
    {
        return $this->entityManager->getRepository(UserEntity::class)->findOneBy([
            'email' => $email
        ]);
    }

    /**
     * Create an authentication token for a user
     * Made public to be used by RegisterService
     */
    public function createToken(UserEntity $user): TokenEntity
    {
        $expires = (new \DateTime())->modify('+1 month');

        $token = new TokenEntity();

        $token->setUser($user);
        $token->setValue(bin2hex(random_bytes(32)) . ':' . $expires->getTimestamp()); 
        $token->setIp($_SERVER['REMOTE_ADDR'] ?? null);
        $token->setUserAgent($_SERVER['HTTP_USER_AGENT'] ?? null);
        $token->setExpires($expires); 
        $token->setCreated(new \DateTime());
        $token->setUpdated(new \DateTime());

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $token->setValue(base64_encode($token->getId() . ':' . $token->getValue()));

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return $token;
    }

    private function validate(array $data = []): void
    {
        $constraints = new Assert\Collection([
            'email' => [
                new Assert\NotBlank(['message' => 'Email is required.']),
                new Assert\Email(['message' => 'Invalid email format.']),
            ],
            'password' => [
                new Assert\NotBlank(['message' => 'Password is required.']),
            ],
        ]);

        if($errors = $this->validatorService->toArray($constraints, $data))
        {
            throw new AccountException(implode(' | ', $errors));
        }
    }
}