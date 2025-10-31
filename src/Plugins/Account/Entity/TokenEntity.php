<?php

namespace App\Plugins\Account\Entity;

use App\Plugins\Account\Repository\TokenRepository;
use Doctrine\ORM\Mapping as ORM;

use DateTime;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: TokenRepository::class)]
#[ORM\Table(name: 'user_tokens')]
class TokenEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint', name: 'token_id')]
    private int $id; 

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: 'token_user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private UserEntity $user;

    #[ORM\Column(type: 'string', length: 255, unique: true, name: 'token_value')]
    private string $value;

    #[ORM\Column(type: 'string', length: 255, nullable: true, name: 'token_ip')]
    private ?string $ip = null; 

    #[ORM\Column(type: 'text', nullable: true, name: 'token_user_agent')]
    private ?string $userAgent = null; 

    #[ORM\Column(type: 'datetime', nullable: false, name: 'token_expires')]
    private DateTimeInterface $expires; 

    #[ORM\Column(type: 'datetime', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'], name: 'token_updated')]
    private DateTimeInterface $updated; 

    #[ORM\Column(type: 'datetime', nullable: false, options: ['default' => 'CURRENT_TIMESTAMP'], name: 'token_created')]
    private DateTimeInterface $created;

    public function __construct()
    {
        $this->updated = new DateTime(); 
        $this->created = new DateTime(); 
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getUser(): UserEntity
    {
        return $this->user;
    }

    public function setUser(UserEntity $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }

    public function getIp(): ?string
    {
        return $this->ip;
    }

    public function setIp(?string $ip): self
    {
        $this->ip = $ip;
        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function getExpires(): DateTimeInterface
    {
        return $this->expires;
    }

    public function setExpires(DateTimeInterface $expires): self
    {
        $this->expires = $expires;
        return $this;
    }

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }

    public function setUpdated(DateTimeInterface $updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(DateTimeInterface $created): self
    {
        $this->created = $created;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'user' => $this->getUser()->toArray(),
            'value' => $this->getValue(),
            'ip' => $this->getIp(),
            'userAgent' => $this->getUserAgent(),
            'expires' => $this->getExpires()->format('Y-m-d H:i:s'),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
        ];
    }
}
