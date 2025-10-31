<?php

namespace App\Plugins\Integrations\Common\Entity;


use Doctrine\ORM\Mapping as ORM;
use App\Plugins\Account\Entity\UserEntity;
use DateTime;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: "App\Plugins\Integrations\Common\Repository\IntegrationRepository")]
#[ORM\Table(name: "user_integrations")]
class IntegrationEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private int $id;

    #[ORM\ManyToOne(targetEntity: UserEntity::class)]
    #[ORM\JoinColumn(name: "user_id", referencedColumnName: "id", nullable: false)]
    private UserEntity $user;

    #[ORM\Column(name: "provider", type: "string", length: 50)]
    private string $provider;

    #[ORM\Column(name: "external_id", type: "string", length: 255, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(name: "name", type: "string", length: 255)]
    private string $name;

    #[ORM\Column(name: "access_token", type: "text", nullable: true)]
    private ?string $accessToken = null;

    #[ORM\Column(name: "refresh_token", type: "text", nullable: true)]
    private ?string $refreshToken = null;

    #[ORM\Column(name: "token_expires", type: "datetime", nullable: true)]
    private ?DateTimeInterface $tokenExpires = null;

    #[ORM\Column(name: "scopes", type: "text", nullable: true)]
    private ?string $scopes = null;

    #[ORM\Column(name: "config", type: "json", nullable: true)]
    private ?array $config = null;

    #[ORM\Column(name: "status", type: "string", length: 50, options: ["default" => "active"])]
    private string $status = 'active';

    #[ORM\Column(name: "last_synced", type: "datetime", nullable: true)]
    private ?DateTimeInterface $lastSynced = null;

    #[ORM\Column(name: "created", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", nullable: false, options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    // Getters and setters
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

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): self
    {
        $this->provider = $provider;
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function setAccessToken(?string $accessToken): self
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function setRefreshToken(?string $refreshToken): self
    {
        $this->refreshToken = $refreshToken;
        return $this;
    }

    public function getTokenExpires(): ?DateTimeInterface
    {
        return $this->tokenExpires;
    }

    public function setTokenExpires(?DateTimeInterface $tokenExpires): self
    {
        $this->tokenExpires = $tokenExpires;
        return $this;
    }

    public function getScopes(): ?string
    {
        return $this->scopes;
    }

    public function setScopes(?string $scopes): self
    {
        $this->scopes = $scopes;
        return $this;
    }

    public function getConfig(): ?array
    {
        return $this->config;
    }

    public function setConfig(?array $config): self
    {
        $this->config = $config;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getLastSynced(): ?DateTimeInterface
    {
        return $this->lastSynced;
    }

    public function setLastSynced(?DateTimeInterface $lastSynced): self
    {
        $this->lastSynced = $lastSynced;
        return $this;
    }

    public function getCreated(): DateTimeInterface
    {
        return $this->created;
    }

    public function getUpdated(): DateTimeInterface
    {
        return $this->updated;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'user_id' => $this->getUser()->getId(),
            'provider' => $this->getProvider(),
            'external_id' => $this->getExternalId(),
            'name' => $this->getName(),
            'status' => $this->getStatus(),
            'last_synced' => $this->getLastSynced()?->format('Y-m-d H:i:s'),
            'created' => $this->getCreated()->format('Y-m-d H:i:s'),
            'updated' => $this->getUpdated()->format('Y-m-d H:i:s'),
        ];
    }
}