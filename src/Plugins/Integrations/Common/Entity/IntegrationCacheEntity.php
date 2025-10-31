<?php

namespace App\Plugins\Integrations\Common\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTime;
use DateTimeInterface;

#[ORM\Entity]
#[ORM\Table(name: "integration_cache")]
#[ORM\Index(columns: ["cache_key", "expires_at"])]
#[ORM\Index(columns: ["expires_at"])]
class IntegrationCacheEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "bigint")]
    private ?int $id = null;

    #[ORM\Column(name: "cache_key", type: "string", length: 255, unique: true)]
    private string $cacheKey;

    #[ORM\Column(name: "cache_value", type: "json")]
    private array $cacheValue;

    #[ORM\Column(name: "expires_at", type: "datetime")]
    private DateTimeInterface $expiresAt;

    #[ORM\Column(name: "created", type: "datetime", options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $created;

    #[ORM\Column(name: "updated", type: "datetime", options: ["default" => "CURRENT_TIMESTAMP"])]
    private DateTimeInterface $updated;

    public function __construct()
    {
        $this->created = new DateTime();
        $this->updated = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCacheKey(): string
    {
        return $this->cacheKey;
    }

    public function setCacheKey(string $cacheKey): self
    {
        $this->cacheKey = $cacheKey;
        return $this;
    }

    public function getCacheValue(): array
    {
        return $this->cacheValue;
    }

    public function setCacheValue(array $cacheValue): self
    {
        $this->cacheValue = $cacheValue;
        return $this;
    }

    public function getExpiresAt(): DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeInterface $expiresAt): self
    {
        $this->expiresAt = $expiresAt;
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

    public function setUpdated(DateTimeInterface $updated): self
    {
        $this->updated = $updated;
        return $this;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new DateTime();
    }
}